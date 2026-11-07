<?php

namespace Tests\Feature;

use App\Entitlements\Entitlements;
use App\Models\AccountManagerWebhookEvent;
use App\Models\Household;
use App\Models\Product;
use App\Models\ShoppingList;
use App\Models\User;
use App\Models\UserEntitlement;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Concerns\MocksOidcProvider;
use Tests\TestCase;

/**
 * ENTITLEMENTS_DRIVER / AUTH_ADMIN_SOURCE=account-manager, the AI feature and user.deleted (contract:
 * AccountManager docs/ENTITLEMENTS-API.md).
 */
class AccountManagerEntitlementsTest extends TestCase
{
    use MocksOidcProvider, RefreshDatabase;

    private const SECRET = 'f00dbabe00000000000000000000000000000000000000000000000000000000';

    /**
     * Answers of the fake AccountManager: "GET entitlements/<sub>" => [status, body] or a closure.
     *
     * @var array<string, array{0: int, 1: array<mixed>}|Closure>
     */
    private array $accountManager = [];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-10 12:00');

        config([
            'entitlements.driver' => 'account-manager',
            'entitlements.admin_source' => 'account-manager',
            'households.account_manager' => ['url' => 'https://account-manager.test', 'key' => 'shoppinglist', 'secret' => self::SECRET, 'report_shares' => false],
            'login.oidc.enabled' => true,
            'login.oidc.registration' => true,
            'login.oidc.required_group' => null,
            'services.openai.key' => 'test-key',
            'services.openai.url' => 'https://openai.test/v1',
        ]);

        Http::fake(function (HttpRequest $request) {
            if (str_starts_with($request->url(), 'https://openai.test/')) {
                return Http::response(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['items' => [['name' => 'Eier', 'amount' => '6 Stk.']]])]]]]]);
            }

            $route = $request->method().' '.Str::after($request->url(), 'https://account-manager.test/api/');
            $answer = $this->accountManager[$route] ?? [404, ['message' => 'Not found.']];

            if ($answer instanceof Closure) {
                return $answer($request);
            }

            return Http::response($answer[1], $answer[0]);
        });

        $this->user = User::factory()->linked('sub-123')->create(['email' => 'cook@example.com']);
    }

    /**
     * @param  list<string>  $features
     * @return array{sub: string, service: string, status: string, admin: bool, features: list<string>, version: int}
     */
    private function snapshot(int $version, string $status = 'active', bool $admin = false, array $features = [], string $sub = 'sub-123'): array
    {
        return ['sub' => $sub, 'service' => 'shoppinglist', 'status' => $status, 'admin' => $admin, 'features' => $features, 'version' => $version];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     * @return TestResponse<Response>
     */
    private function webhook(string $type, array $data, array $options = []): TestResponse
    {
        $body = (string) json_encode([
            'event_id' => $options['event_id'] ?? (string) Str::uuid(),
            'type' => $type,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES);
        $timestamp = (string) ($options['timestamp'] ?? now()->getTimestamp());
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $options['secret'] ?? self::SECRET);

        return $this->call('POST', '/api/account-manager/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ACCOUNT_MANAGER_EVENT' => $type,
            'HTTP_X_ACCOUNT_MANAGER_TIMESTAMP' => $timestamp,
            'HTTP_X_ACCOUNT_MANAGER_SIGNATURE' => $options['signature'] ?? $signature,
        ], content: $body);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $options
     * @return TestResponse<Response>
     */
    private function changed(array $snapshot, array $options = []): TestResponse
    {
        return $this->webhook('entitlements.changed', ['entitlements' => $snapshot], $options);
    }

    private function stored(?User $user = null): ?UserEntitlement
    {
        return UserEntitlement::query()->where('user_id', ($user ?? $this->user)->getKey())->first();
    }

    /**
     * @param  list<string>  $features
     */
    private function store(User $user, string $status, bool $admin = false, int $version = 1, array $features = []): void
    {
        UserEntitlement::query()->create(['user_id' => $user->getKey(), 'status' => $status, 'admin' => $admin, 'features' => $features, 'version' => $version]);
        $user->unsetRelation('entitlement');
    }

    private function createList(User $user, string $name = 'Wocheneinkauf'): ShoppingList
    {
        $id = (string) Str::uuid();
        $this->actingAs($user)->postJson('/sync/lists/push', [['newDocumentState' => ['id' => $id, 'name' => $name]]])
            ->assertOk()->assertExactJson([]);

        return ShoppingList::findOrFail($id);
    }

    /**
     * @return TestResponse<Response>
     */
    private function pantry(User $user): TestResponse
    {
        return $this->actingAs($user)->post('/planner/pantry', ['photos' => [UploadedFile::fake()->image('fridge.jpg')]], ['Accept' => 'application/json']);
    }

    public function test_the_webhook_is_closed_without_an_account_manager_integration(): void
    {
        config(['entitlements.driver' => 'local', 'entitlements.admin_source' => 'local']);

        $this->changed($this->snapshot(1))->assertNotFound();
        $this->assertNull($this->stored());
    }

    public function test_invalid_signatures_and_foreign_services_are_rejected(): void
    {
        $this->changed($this->snapshot(1), ['secret' => str_repeat('0', 64)])->assertUnauthorized();
        $this->changed($this->snapshot(1), ['timestamp' => now()->getTimestamp() - 301])->assertUnauthorized();
        $this->changed([...$this->snapshot(1), 'service' => 'recipes'])->assertUnprocessable();

        $this->assertNull($this->stored());
        $this->assertSame(0, AccountManagerWebhookEvent::query()->count());
    }

    public function test_snapshots_are_applied_once_and_in_version_order(): void
    {
        $eventId = (string) Str::uuid();

        $this->changed($this->snapshot(2, features: ['shoppinglist.ai']), ['event_id' => $eventId])->assertNoContent();
        $this->assertSame(['active', ['shoppinglist.ai'], 2], [$this->stored()?->status, $this->stored()?->features, $this->stored()?->version]);

        $this->changed($this->snapshot(5, 'suspended'), ['event_id' => $eventId])->assertNoContent();
        $this->changed($this->snapshot(2, 'suspended'))->assertNoContent();
        $this->assertSame('active', $this->stored()?->status);

        $this->changed($this->snapshot(3, 'suspended', features: ['shoppinglist.ai']))->assertNoContent();
        $this->assertSame(['suspended', [], 3], [$this->stored()?->status, $this->stored()?->features, $this->stored()?->version]);

        // Unknown subjects and types are acknowledged.
        $this->changed($this->snapshot(1, sub: 'sub-unknown'))->assertNoContent();
        $this->webhook('subscription.renewed', ['foo' => 'bar'])->assertNoContent();
        $this->assertSame(1, UserEntitlement::query()->count());
    }

    public function test_the_ai_features_follow_the_entitlement(): void
    {
        // Linked, never synced: no paid features.
        $this->actingAs($this->user)->getJson('/me')
            ->assertJsonPath('assistant', false)
            ->assertJsonPath('assistant_locked', true)
            ->assertJsonPath('features', []);
        $this->pantry($this->user)->assertForbidden()
            ->assertJson(['message' => 'The AI features are not enabled for your account.', 'feature' => 'shoppinglist.ai']);

        $this->changed($this->snapshot(1, features: ['shoppinglist.ai']))->assertNoContent();
        $this->actingAs($this->user->refresh())->getJson('/me')
            ->assertJsonPath('assistant', true)
            ->assertJsonPath('assistant_locked', false)
            ->assertJsonPath('features', ['shoppinglist.ai']);
        $this->pantry($this->user)->assertOk()->assertJsonPath('items.0.name', 'Eier');

        // Revoked: hidden with the next /me, requests refused right away.
        $this->changed($this->snapshot(2))->assertNoContent();
        $this->pantry($this->user->refresh())->assertForbidden();

        // Users without a linked sign-in keep the local setting.
        $local = User::factory()->create();
        $this->pantry($local)->assertOk();
        $this->assertTrue(Entitlements::has($local, 'shoppinglist.ai'));
    }

    public function test_the_local_driver_keeps_the_config_switch(): void
    {
        config(['entitlements.driver' => 'local']);
        $local = User::factory()->create();

        $this->actingAs($local)->getJson('/me')->assertJsonPath('assistant', true)->assertJsonPath('assistant_locked', false);
        $this->pantry($this->user)->assertOk();

        // AI off for all users, with the key still set.
        config(['entitlements.local.features' => []]);
        $this->actingAs($local)->getJson('/me')->assertJsonPath('assistant', false)->assertJsonPath('assistant_locked', true);
        $this->pantry($local)->assertForbidden();

        // Without a key nothing changes: the planner is not offered.
        config(['services.openai.key' => null]);
        $this->actingAs($local)->getJson('/me')->assertJsonPath('assistant', false)->assertJsonPath('assistant_locked', false);
    }

    public function test_admin_rights_follow_the_admin_source(): void
    {
        config(['login.local.emergency_emails' => ['notfall@example.com']]);
        $emergency = User::factory()->create(['email' => 'notfall@example.com']);
        $this->store($this->user, 'active', admin: true);

        $this->assertTrue(Entitlements::isAdmin($this->user));
        $this->assertTrue(Entitlements::isAdmin($emergency));
        $this->assertFalse(Entitlements::isAdmin(User::factory()->create()));

        config(['entitlements.admin_source' => 'local']);
        $this->assertFalse(Entitlements::isAdmin($this->user));
        $this->assertTrue(Entitlements::isAdmin($emergency));
    }

    public function test_a_blocked_user_is_signed_out_and_the_app_is_told(): void
    {
        $this->store($this->user, 'suspended');

        // The app shell's JSON requests get the reason (the session stays, so /me can tell it again).
        $this->actingAs($this->user)->getJson('/me')->assertForbidden()
            ->assertExactJson(['message' => 'Your access to '.config('app.name').' is suspended.', 'access' => 'suspended']);
        $this->actingAs($this->user)->getJson('/sync/lists/pull')->assertForbidden()->assertJsonPath('access', 'suspended');

        // Pages sign out and show the message on the login page.
        $this->actingAs($this->user)->get('/account')->assertRedirect(route('login'))
            ->assertSessionHasErrors(['access' => 'Your access to '.config('app.name').' is suspended.']);
        $this->assertGuest();

        UserEntitlement::query()->update(['status' => 'requested']);
        $this->actingAs($this->user->refresh())->getJson('/me')->assertForbidden()
            ->assertJson(['message' => 'Your access to '.config('app.name').' is not enabled.', 'access' => 'disabled']);
    }

    public function test_emergency_users_and_the_local_driver_are_not_blocked(): void
    {
        $local = User::factory()->create();
        $this->actingAs($local)->getJson('/me')->assertOk();

        // Never synced: not locked out.
        $this->actingAs($this->user)->getJson('/me')->assertOk();

        $this->store($this->user, 'none');
        config(['entitlements.driver' => 'local']);
        $this->actingAs($this->user->refresh())->getJson('/me')->assertOk();
        $this->actingAs($this->user)->get('/account')->assertRedirect('/#/settings');
    }

    public function test_the_login_fetches_the_snapshot_and_refuses_blocked_users(): void
    {
        $this->accountManager['GET entitlements/sub-123'] = [200, ['data' => $this->snapshot(4, features: ['shoppinglist.ai'])]];

        $this->signIn()->assertRedirect(config('fortify.home'));
        $this->assertAuthenticatedAs($this->user);
        $this->assertSame(['active', ['shoppinglist.ai'], 4], [$this->stored()?->status, $this->stored()?->features, $this->stored()?->version]);
        $this->assertNotNull($this->stored()?->synced_at);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://account-manager.test/api/entitlements/sub-123'
            && $request->header('Authorization') === ['Basic '.base64_encode('shoppinglist:'.self::SECRET)]);

        auth()->logout();
        $this->accountManager['GET entitlements/sub-123'] = [404, ['message' => 'Not found.']];

        $this->signIn()->assertRedirect(route('login'))
            ->assertSessionHasErrors(['oidc' => 'Your access to '.config('app.name').' is not enabled.']);
        $this->assertGuest();
        $this->assertSame(['none', 4], [$this->stored()?->status, $this->stored()?->version]);
    }

    public function test_the_login_works_with_the_last_state_while_the_account_manager_is_down(): void
    {
        $this->store($this->user, 'active', version: 3, features: ['shoppinglist.ai']);
        $this->accountManager['GET entitlements/sub-123'] = fn () => Http::failedConnection();
        $log = Log::spy();

        $this->signIn()->assertRedirect(config('fortify.home'));

        $this->assertAuthenticatedAs($this->user);
        $this->assertSame(['active', ['shoppinglist.ai'], 3], [$this->stored()?->status, $this->stored()?->features, $this->stored()?->version]);
        $log->shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'entitlements on sign-in'))->once();
    }

    public function test_the_sync_command_fetches_all_linked_users_and_keeps_the_state_when_down(): void
    {
        $other = User::factory()->linked('sub-other')->create();
        User::factory()->create();
        $this->store($other, 'active', version: 7, features: ['shoppinglist.ai']);
        $this->accountManager['GET entitlements/sub-123'] = [200, ['data' => $this->snapshot(2, features: ['shoppinglist.ai'])]];

        $this->artisan('entitlements:sync')->expectsOutputToContain('2 user(s)')->assertSuccessful();

        $this->assertSame(['active', 2], [$this->stored()?->status, $this->stored()?->version]);
        $this->assertSame(['none', [], 7], [$this->stored($other)?->status, $this->stored($other)?->features, $this->stored($other)?->version]);

        $this->accountManager['GET entitlements/sub-123'] = [503, ['message' => 'Down']];
        $this->artisan('entitlements:sync')->assertFailed();
        $this->assertSame(['active', 2], [$this->stored()?->status, $this->stored()?->version]);
    }

    public function test_unlinking_drops_the_snapshot(): void
    {
        $this->store($this->user, 'active', version: 9);

        $this->user->forceFill(['oidc_sub' => null, 'password' => 'secret-password'])->save();

        $this->assertNull($this->stored());
    }

    public function test_user_deleted_deletes_private_contents_and_hands_over_shared_lists(): void
    {
        Storage::fake();
        $member = User::factory()->create();
        $other = User::factory()->create();
        $private = $this->createList($this->user, 'Privat');
        $shared = $this->createList($this->user, 'Geteilt');
        $shared->addMember($member);
        $foreign = $this->createList($other, 'Fremd');
        $foreign->addMember($this->user);
        $household = new Household(['name' => 'WG']);
        $household->creator()->associate($this->user)->save();
        $household->addMember($this->user, 'admin');
        $household->addMember($member);
        $product = new Product(['name' => 'Spezialmehl']);
        $product->forceFill(['owner_id' => $this->user->id, 'image' => (string) Str::uuid()])->save();
        Storage::put('images/'.$product->image, 'jpg');
        $this->store($this->user, 'active');

        $this->webhook('user.deleted', ['user' => ['sub' => 'sub-123']])->assertNoContent();

        $this->assertNull(User::find($this->user->id));
        $this->assertNull(ShoppingList::withTrashed()->find($private->id));
        $this->assertSame($member->id, $shared->fresh()?->owner_id);
        $this->assertFalse($shared->fresh()?->trashed());
        $this->assertTrue($shared->fresh()?->hasMember($member));
        $this->assertSame(0, $foreign->memberships()->where('user_id', $this->user->id)->count());
        $this->assertSame('admin', $household->fresh()?->roleOf($member));
        $this->assertNull(Product::withTrashed()->find($product->id));
        Storage::assertMissing('images/'.$product->image);
        $this->assertSame(0, UserEntitlement::query()->count());

        // The new owner's devices get the change.
        $document = collect($this->actingAs($member)->getJson('/sync/lists/pull')->json('documents'))->firstWhere('id', $shared->id);
        $this->assertTrue($document['is_owner']);

        // Repeated delivery and unknown subjects: acknowledged, nothing happens.
        $this->webhook('user.deleted', ['user' => ['sub' => 'sub-123']])->assertNoContent();
        $this->webhook('user.deleted', ['user' => ['sub' => 'sub-unknown']])->assertNoContent();
        $this->assertNotNull($other->fresh());
    }

    public function test_api_answers_always_apply_also_older_versions(): void
    {
        $this->store($this->user, 'active', admin: true, version: 9);

        // After a backup restore the AccountManager answers with a lower version: the API is the current truth.
        $this->accountManager['GET entitlements/sub-123'] = [200, ['data' => $this->snapshot(3, 'suspended')]];
        $this->artisan('entitlements:sync')->assertSuccessful();

        $this->assertSame(['suspended', false, 3], [$this->stored()?->status, $this->stored()?->admin, $this->stored()?->version]);
        $this->assertNotNull($this->stored()?->synced_at);

        // Webhooks stay strict: equal or lower versions are ignored, higher ones apply.
        $this->changed($this->snapshot(3))->assertNoContent();
        $this->changed($this->snapshot(2))->assertNoContent();
        $this->assertSame('suspended', $this->stored()?->status);
        $this->changed($this->snapshot(4))->assertNoContent();
        $this->assertSame(['active', 4], [$this->stored()?->status, $this->stored()?->version]);
    }
}
