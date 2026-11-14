<?php

namespace Tests\Feature;

use App\Households\AccountManagerClient;
use App\Households\AccountManagerUsers;
use App\Jobs\ReportUserToAccountManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\MocksOidcProvider;
use Tests\TestCase;

/**
 * ACCOUNT_MANAGER_REPORT_USERS: the users of this app are reported to the AccountManager (contract: AccountManager
 * docs/SERVICE-USERS-API.md). Same file as in Chorelist.
 */
class AccountManagerUsersTest extends TestCase
{
    use MocksOidcProvider, RefreshDatabase;

    private const BASE = 'https://konto.example.test/api';

    /**
     * The AccountManager answers 503.
     */
    private bool $down = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'households.account_manager.url' => 'https://konto.example.test',
            'households.account_manager.key' => 'svc-app',
            'households.account_manager.secret' => 'secret-123',
            'households.account_manager.report_users' => true,
            'login.oidc.enabled' => true,
            'login.oidc.label' => 'Pocket ID',
            'login.oidc.registration' => true,
            'login.oidc.required_group' => null,
        ]);

        Http::fake(fn (HttpRequest $request) => match (true) {
            $this->down => Http::response('down', 503),
            $request->method() === 'DELETE' => Http::response(null, 204),
            $request->method() === 'PUT' => Http::response(['upserted' => count($request['users']), 'removed' => 0]),
            default => Http::response(['ok' => true]),
        });
    }

    /**
     * @return list<HttpRequest>
     */
    private function sent(string $method, string $url = self::BASE.'/service-users'): array
    {
        return Http::recorded(fn (HttpRequest $request) => $request->method() === $method && $request->url() === $url)
            ->map(fn (array $pair) => $pair[0])->values()->all();
    }

    public function test_it_is_off_by_default_and_without_configuration(): void
    {
        config(['households.account_manager.report_users' => false]);
        $this->assertFalse(AccountManagerUsers::enabled());
        User::factory()->create();

        config(['households.account_manager.report_users' => true, 'households.account_manager.secret' => null]);
        $this->assertFalse(AccountManagerUsers::enabled());
        User::factory()->create();

        Http::assertNothingSent();
    }

    public function test_a_new_user_is_upserted_with_http_basic(): void
    {
        $user = User::factory()->create(['email' => 'anna@example.com', 'name' => 'Anna']);

        $requests = $this->sent('POST');
        $this->assertCount(1, $requests);
        $this->assertSame('Basic '.base64_encode('svc-app:secret-123'), $requests[0]->header('Authorization')[0]);
        $this->assertSame([
            'id' => (string) $user->id,
            'email' => 'anna@example.com',
            'name' => 'Anna',
            'oidc_sub' => null,
            'last_login_at' => null,
            'created_at' => $user->created_at->toIso8601String(),
        ], $requests[0]->data());
    }

    public function test_login_records_the_time_and_reports_it(): void
    {
        $user = User::factory()->create(['email' => 'anna@example.com']);
        $this->freezeSecond();

        $this->post('/login', ['email' => 'anna@example.com', 'password' => 'password'])->assertRedirect();

        $this->assertSame(now()->toIso8601String(), $user->refresh()->last_login_at->toIso8601String());
        $last = collect($this->sent('POST'))->last();
        $this->assertSame(now()->toIso8601String(), $last['last_login_at']);
    }

    public function test_linking_the_oidc_login_reports_the_subject(): void
    {
        User::factory()->create(['email' => 'link@example.com']);

        $this->signIn(['sub' => 'sub-link', 'email' => 'link@example.com'])->assertRedirect();

        $this->assertContains('sub-link', collect($this->sent('POST'))->map(fn (HttpRequest $request) => $request['oidc_sub'])->all());
    }

    public function test_only_reported_attributes_trigger_an_upsert(): void
    {
        $user = User::factory()->create();
        $before = count($this->sent('POST'));

        $user->forceFill(['locale' => 'en'])->save();
        $this->assertCount($before, $this->sent('POST'));

        $user->forceFill(['email' => 'changed@example.com'])->save();
        $this->assertCount($before + 1, $this->sent('POST'));
    }

    public function test_deleting_the_account_reports_the_deletion(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->deleteJson('/account', ['password' => 'password'])->assertOk();

        $this->assertCount(1, $this->sent('DELETE', self::BASE.'/service-users/'.$user->id));
    }

    public function test_jobs_are_queued_with_retries(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $user->delete();

        Queue::assertPushed(ReportUserToAccountManager::class, fn ($job) => $job->action === 'upsert' && $job->userId === (string) $user->id && $job->tries === 3);
        Queue::assertPushed(ReportUserToAccountManager::class, fn ($job) => $job->action === 'delete');
    }

    public function test_failures_never_break_the_login(): void
    {
        $user = User::factory()->create(['email' => 'anna@example.com']);
        $this->down = true;

        $this->post('/login', ['email' => 'anna@example.com', 'password' => 'password'])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_full_sync_sends_all_users_in_one_complete_request(): void
    {
        $users = User::factory()->count(3)->create();

        $this->artisan('account-manager:report-users')->assertSuccessful()
            ->expectsOutputToContain('Reported 3 user(s)');

        $requests = $this->sent('PUT');
        $this->assertCount(1, $requests);
        $this->assertTrue($requests[0]['complete']);
        $this->assertSame($users->pluck('id')->map(fn ($id) => (string) $id)->all(), array_column($requests[0]['users'], 'id'));
        $this->assertNotEmpty($requests[0]->header('X-Sync-Started-At')[0]);
    }

    public function test_the_full_sync_chunks_with_one_start_time_and_completes_last(): void
    {
        User::factory()->count(5)->create();

        $result = app(AccountManagerUsers::class)->syncAll(app(AccountManagerClient::class), chunk: 2);

        $this->assertSame(['users' => 5, 'upserted' => 5, 'removed' => 0], $result);
        $requests = $this->sent('PUT');
        $this->assertSame([false, false, true], array_map(fn ($request) => $request['complete'], $requests));
        $this->assertSame([2, 2, 1], array_map(fn ($request) => count($request['users']), $requests));
        $this->assertCount(1, array_unique(array_map(fn ($request) => $request->header('X-Sync-Started-At')[0], $requests)));
    }

    public function test_an_empty_app_sends_an_empty_complete_list(): void
    {
        $this->artisan('account-manager:report-users')->assertSuccessful();

        $requests = $this->sent('PUT');
        $this->assertCount(1, $requests);
        $this->assertSame(['users' => [], 'complete' => true], $requests[0]->data());
    }

    public function test_the_full_sync_fails_when_the_account_manager_is_down(): void
    {
        $this->down = true;

        $this->artisan('account-manager:report-users')->assertFailed();
    }

    public function test_the_full_sync_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('account-manager:report-users');
    }
}
