<?php

namespace Tests\Feature;

use App\Jobs\ApplyAccountManagerEvent;
use App\Models\AccountManagerWebhookEvent;
use App\Models\Household;
use App\Models\HouseholdPendingMember;
use App\Models\Item;
use App\Models\ShoppingList;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * HOUSEHOLDS_DRIVER=account-manager: households mirrored from the AccountManager (contract: AccountManager
 * docs/HOUSEHOLDS-API.md).
 */
class AccountManagerHouseholdTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'f00dbabe00000000000000000000000000000000000000000000000000000000';

    private const UUID = '019a5e3c-6f1d-7b2a-9c43-0d8e5f6a7b8c';

    /**
     * Answers of the fake AccountManager: "GET households/<uuid>" => [status, body] (or a closure).
     *
     * @var array<string, mixed>
     */
    private array $accountManager = [];

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 12:00');
        config([
            'households.driver' => 'account-manager',
            'households.account_manager' => ['url' => 'https://account-manager.test', 'key' => 'shoppinglist', 'secret' => self::SECRET, 'report_shares' => false],
        ]);

        Http::fake(function (HttpRequest $request) {
            $route = $request->method().' '.Str::after($request->url(), 'https://account-manager.test/api/');
            $answer = $this->accountManager[$route] ?? [404, ['message' => 'Not found.']];
            if ($answer instanceof Closure) {
                return $answer($request);
            }

            return Http::response($answer[1], $answer[0]);
        });

        $this->owner = User::factory()->create(['email' => 'olivia@example.com']);
        $this->owner->forceFill(['oidc_sub' => 'sub-owner'])->save();
        $this->member = User::factory()->create(['email' => 'max@example.com']);
        $this->member->forceFill(['oidc_sub' => 'sub-member'])->save();
    }

    private function snapshot(int $version, array $members = ['sub-owner' => 'admin', 'sub-member' => 'member'], string $name = 'Familie Muster', string $uuid = self::UUID): array
    {
        return [
            'uuid' => $uuid,
            'name' => $name,
            'version' => $version,
            'members' => array_map(fn ($sub, $role) => ['sub' => $sub, 'role' => $role], array_keys($members), $members),
        ];
    }

    private function webhook(string $type, array $snapshot, array $options = []): TestResponse
    {
        $body = json_encode([
            'event_id' => $options['event_id'] ?? (string) Str::uuid(),
            'type' => $type,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'data' => ['household' => $snapshot, ...($options['data'] ?? [])],
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
     * The household arrives with household.created (unknown: confirmed through the API).
     */
    private function mirror(array $snapshot): Household
    {
        $this->accountManager['GET households/'.$snapshot['uuid']] = [200, ['data' => $snapshot]];
        $this->webhook('household.created', $snapshot)->assertNoContent();

        return Household::where('account_manager_household_uuid', $snapshot['uuid'])->firstOrFail();
    }

    private function pull(User $user, string $collection, array $checkpoint = []): TestResponse
    {
        return $this->actingAs($user)->getJson("/sync/{$collection}/pull?".http_build_query($checkpoint));
    }

    private function push(User $user, string $collection, array $rows): TestResponse
    {
        return $this->actingAs($user)->postJson("/sync/{$collection}/push", $rows);
    }

    private function listDoc(User $user, string $listId): ?array
    {
        return collect($this->pull($user, 'lists')->json('documents'))->firstWhere('id', $listId);
    }

    /**
     * A list of $owner with an item, shared with the household.
     */
    private function sharedList(User $owner, Household $household, string $name = 'Wocheneinkauf'): string
    {
        $id = (string) Str::uuid();
        $this->push($owner, 'lists', [['newDocumentState' => ['id' => $id, 'name' => $name]]])->assertExactJson([]);
        $this->push($owner, 'items', [['newDocumentState' => ['id' => (string) Str::uuid(), 'shopping_list_id' => $id, 'name' => 'Milch']]])
            ->assertExactJson([]);
        $this->actingAs($owner)->postJson("/lists/{$id}/households", ['household' => $household->uuid])->assertNoContent();

        return $id;
    }

    private function localHousehold(string $name, array $members): Household
    {
        $household = new Household(['name' => $name]);
        $household->save();
        foreach ($members as [$user, $role]) {
            $household->addMember($user, $role);
        }

        return $household;
    }

    public function test_events_of_unknown_households_are_confirmed_through_the_api(): void
    {
        $household = $this->mirror($this->snapshot(1, ['sub-owner' => 'admin', 'sub-member' => 'member', 'sub-unknown' => 'member']));

        $this->assertSame('Familie Muster', $household->name);
        $this->assertSame(1, $household->account_manager_version);
        $this->assertNull($household->created_by);
        $this->assertSame('admin', $household->roleOf($this->owner));
        $this->assertSame('member', $household->roleOf($this->member));
        $this->assertSame(['sub-unknown'], $household->pendingMembers()->pluck('oidc_sub')->all());
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'GET'
            && $request->url() === 'https://account-manager.test/api/households/'.self::UUID
            && $request->header('Authorization') === ['Basic '.base64_encode('shoppinglist:'.self::SECRET)]
            && $request->header('Accept') === ['application/json']);

        // An event after the deletion (404) creates nothing.
        $this->accountManager['GET households/'.self::UUID] = [404, ['message' => 'Not found.']];
        $this->webhook('household.deleted', [...$this->snapshot(2, []), 'deleted' => true])->assertNoContent();
        $this->webhook('household.member_added', $this->snapshot(1))->assertNoContent();
        $this->assertSame(0, Household::count());
        $this->assertSame(0, HouseholdPendingMember::count());
    }

    public function test_invalid_and_expired_signatures_are_rejected(): void
    {
        $snapshot = $this->snapshot(1);
        $this->accountManager['GET households/'.self::UUID] = [200, ['data' => $snapshot]];

        $this->webhook('household.created', $snapshot, ['secret' => str_repeat('0', 64)])->assertUnauthorized();
        $this->webhook('household.created', $snapshot, ['signature' => ''])->assertUnauthorized();
        $this->webhook('household.created', $snapshot, ['timestamp' => now()->getTimestamp() - 301])->assertUnauthorized();
        $this->webhook('household.created', $snapshot, ['timestamp' => now()->getTimestamp() + 301])->assertUnauthorized();
        $this->webhook('household.created', $snapshot, ['timestamp' => 'abc'])->assertUnauthorized();
        $this->postJson('/api/account-manager/webhook', ['event_id' => (string) Str::uuid(), 'type' => 'household.created', 'data' => ['household' => $snapshot]])->assertUnauthorized();
        $this->assertSame(0, Household::count());
        $this->assertSame(0, AccountManagerWebhookEvent::count());
        Http::assertNothingSent();

        // Within the tolerance, and the signed body has to be valid.
        $this->webhook('household.created', ['uuid' => 'nope'])->assertUnprocessable();
        $this->webhook('household.created', $snapshot, ['timestamp' => now()->getTimestamp() - 299])->assertNoContent();
        $this->assertSame(1, Household::count());
    }

    public function test_the_local_driver_rejects_webhooks(): void
    {
        config(['households.driver' => 'local']);
        $this->webhook('household.created', $this->snapshot(1))->assertNotFound();

        config(['households.driver' => 'account-manager', 'households.account_manager.secret' => null]);
        $this->webhook('household.created', $this->snapshot(1))->assertNotFound();

        $this->assertSame(0, Household::count());
        Http::assertNothingSent();
    }

    public function test_events_are_applied_once(): void
    {
        $this->mirror($this->snapshot(1));
        Queue::fake();
        $eventId = (string) Str::uuid();

        $this->webhook('household.updated', $this->snapshot(2, name: 'Muster'), ['event_id' => $eventId])->assertNoContent();
        $this->webhook('household.updated', $this->snapshot(2, name: 'Muster'), ['event_id' => $eventId])->assertNoContent();

        Queue::assertPushed(ApplyAccountManagerEvent::class, 1);
        $this->assertSame(2, AccountManagerWebhookEvent::count());

        // Unknown types without a household are acknowledged.
        $this->webhook('user.deleted', [], ['data' => ['household' => null]])->assertNoContent();
        Queue::assertPushed(ApplyAccountManagerEvent::class, 1);
        $this->travel(8)->days();
        $this->artisan('model:prune', ['--model' => [AccountManagerWebhookEvent::class]])->assertSuccessful();
        $this->assertSame(0, AccountManagerWebhookEvent::count());
    }

    public function test_stale_versions_are_ignored(): void
    {
        $household = $this->mirror($this->snapshot(3));
        $listId = $this->sharedList($this->owner, $household);

        $this->webhook('household.updated', $this->snapshot(3, name: 'Gleich'))->assertNoContent();
        $this->webhook('household.member_removed', $this->snapshot(2, ['sub-owner' => 'admin']))->assertNoContent();
        $this->assertSame('Familie Muster', $household->fresh()->name);
        $this->assertTrue($household->hasMember($this->member));
        $this->assertFalse($this->listDoc($this->member, $listId)['_deleted']);

        $checkpoint = $this->pull($this->member, 'lists')->json('checkpoint');
        Carbon::setTestNow(now()->addMinute());
        // Unknown types with a household count as household.updated.
        $this->webhook('household.archived', $this->snapshot(4, name: 'Familie Meier'))->assertNoContent();
        $this->assertSame('Familie Meier', $household->fresh()->name);
        $this->assertSame(4, $household->fresh()->account_manager_version);
        // The household name is part of the list documents.
        $this->assertSame('Familie Meier', $this->pull($this->member, 'lists', $checkpoint)->json('documents.0.households.0.name'));
    }

    public function test_member_changes_grant_and_revoke_access_to_the_shared_lists(): void
    {
        $household = $this->mirror($this->snapshot(1));
        $listId = $this->sharedList($this->owner, $household);
        $itemId = Item::first()->id;
        $doc = $this->listDoc($this->member, $listId);
        $this->assertFalse($doc['_deleted']);
        $this->assertSame([['id' => $household->uuid, 'name' => 'Familie Muster']], $doc['households']);
        $checkpoint = $this->pull($this->member, 'lists')->json('checkpoint');
        $item = $this->pull($this->member, 'items')->json('documents.0');
        $this->assertSame($itemId, $item['id']);
        Carbon::setTestNow(now()->addMinute());

        // Removed: the next pull removes the list from the device, offline pushes are ignored.
        $this->webhook('household.member_removed', $this->snapshot(2, ['sub-owner' => 'admin']), ['data' => ['member' => ['sub' => 'sub-member', 'role' => 'member']]])->assertNoContent();
        $this->assertFalse($household->hasMember($this->member));
        $this->pull($this->member, 'lists', $checkpoint)
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.id', $listId)
            ->assertJsonPath('documents.0._deleted', true);
        $this->assertSame([], $this->pull($this->member, 'items')->json('documents'));
        $this->push($this->member, 'items', [['assumedMasterState' => $item, 'newDocumentState' => [...$item, 'name' => 'Offline', 'version' => $item['version'] + 1]]])->assertOk();
        $this->push($this->member, 'items', [['newDocumentState' => ['id' => (string) Str::uuid(), 'shopping_list_id' => $listId, 'name' => 'Neu']]])->assertOk();
        $this->assertSame('Milch', Item::find($itemId)->name);
        $this->assertSame(1, Item::count());
        $this->assertFalse($this->listDoc($this->owner, $listId)['_deleted']);

        // Added again (as admin), then the role changes.
        $this->webhook('household.member_added', $this->snapshot(3, ['sub-owner' => 'admin', 'sub-member' => 'admin']))->assertNoContent();
        $this->assertSame('admin', $household->roleOf($this->member));
        $this->assertFalse($this->listDoc($this->member, $listId)['_deleted']);
        $this->assertCount(1, $this->pull($this->member, 'items')->json('documents'));
        $this->webhook('household.member_updated', $this->snapshot(4, ['sub-owner' => 'admin', 'sub-member' => 'member']))->assertNoContent();
        $this->assertSame('member', $household->roleOf($this->member));

        // A new member who uses the app already gets the list right away.
        $newcomer = User::factory()->create();
        $newcomer->forceFill(['oidc_sub' => 'sub-newcomer'])->save();
        $this->webhook('household.member_added', $this->snapshot(5, ['sub-owner' => 'admin', 'sub-member' => 'member', 'sub-newcomer' => 'member']))->assertNoContent();
        $this->assertFalse($this->listDoc($newcomer, $listId)['_deleted']);
        $this->assertCount(1, $this->pull($newcomer, 'items')->json('documents'));
    }

    public function test_removed_members_no_longer_share_their_own_lists(): void
    {
        $household = $this->mirror($this->snapshot(1));
        $listId = $this->sharedList($this->member, $household, 'Max');
        $this->assertFalse($this->listDoc($this->owner, $listId)['_deleted']);

        $this->webhook('household.member_removed', $this->snapshot(2, ['sub-owner' => 'admin']))->assertNoContent();

        $this->assertSame([], ShoppingList::find($listId)->households()->pluck('households.id')->all());
        $this->assertTrue($this->listDoc($this->owner, $listId)['_deleted']);
        $this->assertFalse($this->listDoc($this->member, $listId)['_deleted']);
    }

    public function test_a_deleted_household_removes_only_the_shares(): void
    {
        $household = $this->mirror($this->snapshot(1));
        $listId = $this->sharedList($this->owner, $household);

        $this->webhook('household.deleted', [...$this->snapshot(2, []), 'deleted' => true])->assertNoContent();

        $this->assertNull(Household::find($household->id));
        $this->assertTrue($this->listDoc($this->member, $listId)['_deleted']);
        $this->assertSame([], $this->pull($this->member, 'items')->json('documents'));
        $doc = $this->listDoc($this->owner, $listId);
        $this->assertFalse($doc['_deleted']);
        $this->assertSame([], $doc['households']);
        $this->assertSame(1, Item::count());
    }

    private function oidcLogin(string $sub, string $email): TestResponse
    {
        config(['login.oidc.enabled' => true, 'login.oidc.registration' => true, 'login.oidc.required_group' => null]);
        $identity = (new SocialiteUser)->setRaw(['sub' => $sub, 'email' => $email, 'email_verified' => true])
            ->map(['id' => $sub, 'email' => $email, 'name' => 'Neu', 'nickname' => null]);
        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($identity);
        Socialite::shouldReceive('driver')->with('oidc')->andReturn($provider);

        return $this->get(route('oidc.callback'));
    }

    public function test_pending_members_are_attached_on_their_first_oidc_login(): void
    {
        $snapshot = $this->snapshot(1, ['sub-owner' => 'admin', 'sub-new' => 'member']);
        $household = $this->mirror($snapshot);
        $listId = $this->sharedList($this->owner, $household);
        $this->assertSame(1, $household->pendingMembers()->count());
        $this->accountManager['GET households?sub=sub-new'] = [200, ['data' => [$snapshot]]];

        $this->oidcLogin('sub-new', 'neu@example.com')->assertRedirect(config('fortify.home'));

        $user = User::where('oidc_sub', 'sub-new')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('member', $household->roleOf($user));
        $this->assertSame(0, $household->pendingMembers()->count());
        $this->assertFalse($this->listDoc($user, $listId)['_deleted']);
        $this->assertCount(1, $this->pull($user, 'items')->json('documents'));
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://account-manager.test/api/households?sub=sub-new');
    }

    public function test_the_login_works_while_the_account_manager_is_down(): void
    {
        $household = $this->mirror($this->snapshot(1, ['sub-owner' => 'admin', 'sub-new' => 'member']));
        $this->accountManager['GET households?sub=sub-new'] = fn () => Http::failedConnection();
        Log::spy();

        $this->oidcLogin('sub-new', 'neu@example.com')->assertRedirect(config('fortify.home'));

        $user = User::where('oidc_sub', 'sub-new')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        // The pending membership is known locally.
        $this->assertTrue($household->hasMember($user));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'on login'))->once();
    }

    public function test_unlinking_the_login_leaves_the_mirrored_households(): void
    {
        $household = $this->mirror($this->snapshot(1));
        $listId = $this->sharedList($this->owner, $household);

        $this->actingAs($this->member)->delete(route('oidc.unlink'))->assertRedirect(route('account'));

        $this->assertFalse($household->hasMember($this->member));
        $this->assertSame(['sub-member'], $household->pendingMembers()->pluck('oidc_sub')->all());
        $this->assertTrue($this->listDoc($this->member, $listId)['_deleted']);
    }

    public function test_the_sync_command_mirrors_all_households(): void
    {
        $kept = $this->mirror($this->snapshot(1));
        $left = $this->mirror($this->snapshot(1, ['sub-owner' => 'admin'], 'Ferienhaus', '019a5e3c-0000-7000-8000-000000000002'));
        $gone = $this->mirror($this->snapshot(1, ['sub-member' => 'admin'], 'WG', '019a5e3c-0000-7000-8000-000000000003'));
        $goneList = $this->sharedList($this->member, $gone, 'WG');
        $this->sharedList($this->owner, $left);
        Http::fake(); // only resets the recorded requests, the fake AccountManager stays

        $this->accountManager = [
            'GET households?sub=sub-owner' => [200, ['data' => [
                $this->snapshot(2, name: 'Familie Meier'),
                $this->snapshot(1, ['sub-owner' => 'member', 'sub-later' => 'admin'], 'Neu', '019a5e3c-0000-7000-8000-000000000004'),
            ]]],
            'GET households?sub=sub-member' => [200, ['data' => [$this->snapshot(2, name: 'Familie Meier')]]],
            // The owner left: the household no longer concerns this app's users → checked one by one.
            'GET households/'.$left->account_manager_household_uuid => [200, ['data' => $this->snapshot(2, ['sub-other' => 'admin'], 'Ferienhaus', $left->account_manager_household_uuid)]],
            'GET households/'.$gone->account_manager_household_uuid => [404, ['message' => 'Not found.']],
        ];

        $this->artisan('households:sync')->assertSuccessful();

        $this->assertSame('Familie Meier', $kept->fresh()->name);
        $this->assertSame(2, $kept->fresh()->account_manager_version);
        $this->assertFalse($left->hasMember($this->owner));
        $this->assertSame(['sub-other'], $left->pendingMembers()->pluck('oidc_sub')->all());
        $this->assertNull(Household::find($gone->id));
        $this->assertSame([], ShoppingList::find($goneList)->households()->pluck('households.id')->all());
        $new = Household::where('account_manager_household_uuid', '019a5e3c-0000-7000-8000-000000000004')->firstOrFail();
        $this->assertSame('member', $new->roleOf($this->owner));
        $this->assertSame(['sub-later'], $new->pendingMembers()->pluck('oidc_sub')->all());
        // Every household is fetched once.
        Http::assertSentCount(4);
    }

    public function test_the_sync_pull_revokes_access_of_members_removed_in_the_account_manager(): void
    {
        $household = $this->mirror($this->snapshot(1));
        $listId = $this->sharedList($this->owner, $household);
        $checkpoint = $this->pull($this->member, 'lists')->json('checkpoint');
        $item = $this->pull($this->member, 'items')->json('documents.0');
        Carbon::setTestNow(now()->addMinute());
        // The webhook got lost: the daily sync brings the removal.
        $this->accountManager = [
            'GET households?sub=sub-owner' => [200, ['data' => [$this->snapshot(2, ['sub-owner' => 'admin'])]]],
            'GET households?sub=sub-member' => [200, ['data' => []]],
        ];

        $this->artisan('households:sync')->assertSuccessful();

        $this->assertFalse($household->hasMember($this->member));
        $this->pull($this->member, 'lists', $checkpoint)->assertJsonPath('documents.0.id', $listId)->assertJsonPath('documents.0._deleted', true);
        $this->assertSame([], $this->pull($this->member, 'items')->json('documents'));
        $this->push($this->member, 'items', [['assumedMasterState' => $item, 'newDocumentState' => [...$item, 'name' => 'Offline', 'version' => $item['version'] + 1]]])->assertOk();
        $this->assertSame('Milch', Item::first()->name);
        $this->assertFalse($this->listDoc($this->owner, $listId)['_deleted']);
    }

    public function test_the_last_state_stays_while_the_account_manager_is_down(): void
    {
        $household = $this->mirror($this->snapshot(1));
        $listId = $this->sharedList($this->owner, $household);
        $this->accountManager = ['GET households?sub=sub-owner' => fn () => Http::failedConnection(), 'GET households?sub=sub-member' => [503, []]];
        Log::spy();

        $this->artisan('households:sync')->assertFailed();

        $this->assertTrue($household->fresh()->hasMember($this->member));
        $this->assertFalse($this->listDoc($this->member, $listId)['_deleted']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'households:sync'))->once();

        // An event of an unknown household cannot be confirmed: nothing is created, the job retries.
        $this->webhook('household.created', $this->snapshot(1, uuid: '019a5e3c-0000-7000-8000-000000000009'))->assertNoContent();
        $this->assertSame(1, Household::count());
    }

    public function test_local_households_are_proposed_and_mapped(): void
    {
        $local = User::factory()->create();
        // Mirrored already (the event came before the poll): the second proposal is merged into it.
        $mirrored = $this->mirror($this->snapshot(1, ['sub-owner' => 'admin'], 'Ferienhaus', '019a5e3c-0000-7000-8000-000000000002'));
        config(['households.driver' => 'local']);
        $family = $this->localHousehold('Familie', [[$this->owner, 'admin'], [$this->member, 'member'], [$local, 'member']]);
        $holiday = $this->localHousehold('Ferien', [[$this->owner, 'admin']]);
        $nobody = $this->localHousehold('Ohne SSO', [[$local, 'admin']]);
        $familyList = $this->sharedList($this->owner, $family);
        $holidayList = $this->sharedList($this->owner, $holiday, 'Ferien');
        $this->assertFalse($this->listDoc($local, $familyList)['_deleted']);
        config(['households.driver' => 'account-manager']);

        $pending = [
            ['local_id' => (string) $family->id, 'status' => 'pending', 'household_uuid' => null, 'household' => null],
            ['local_id' => (string) $holiday->id, 'status' => 'pending', 'household_uuid' => null, 'household' => null],
        ];
        $this->accountManager['POST households/import'] = [202, ['data' => $pending]];
        $this->accountManager['GET households/import'] = [200, ['data' => $pending]];

        $this->artisan('households:import')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST' && $request->data() === ['households' => [
            ['local_id' => (string) $family->id, 'name' => 'Familie', 'members' => [['sub' => 'sub-owner', 'role' => 'admin'], ['sub' => 'sub-member', 'role' => 'member']]],
            ['local_id' => (string) $holiday->id, 'name' => 'Ferien', 'members' => [['sub' => 'sub-owner', 'role' => 'admin']]],
        ]]);
        $this->assertSame('pending', $family->fresh()->account_manager_import_status);
        $this->assertNull($nobody->fresh()->account_manager_import_status);
        $this->actingAs($this->owner)->get('/households')->assertOk()->assertSee('Proposed for your account, waiting for confirmation.');

        // The admin of the AccountManager confirmed both.
        $this->accountManager['GET households/import'] = [200, ['data' => [
            ['local_id' => (string) $family->id, 'status' => 'confirmed', 'household_uuid' => self::UUID, 'household' => $this->snapshot(1, name: 'Familie Muster')],
            ['local_id' => (string) $holiday->id, 'status' => 'confirmed', 'household_uuid' => $mirrored->account_manager_household_uuid, 'household' => $this->snapshot(2, ['sub-owner' => 'admin'], 'Ferienhaus', $mirrored->account_manager_household_uuid)],
        ]]];

        $this->artisan('households:import --check')->assertSuccessful();

        $family->refresh();
        $this->assertSame(self::UUID, $family->account_manager_household_uuid);
        $this->assertSame('Familie Muster', $family->name);
        $this->assertNull($family->account_manager_import_status);
        $this->assertTrue($family->hasMember($this->member));
        // Members without a linked login are not in the AccountManager's household.
        $this->assertFalse($family->hasMember($local));
        $this->assertTrue($this->listDoc($local, $familyList)['_deleted']);
        $this->assertFalse($this->listDoc($this->member, $familyList)['_deleted']);
        $this->assertSame('Familie Muster', $this->listDoc($this->member, $familyList)['households'][0]['name']);

        $this->assertNull(Household::find($holiday->id));
        $this->assertSame([$holidayList], $mirrored->shoppingLists()->pluck('shopping_lists.id')->all());
        $this->assertSame(2, $mirrored->fresh()->account_manager_version);

        // Nothing pending anymore: the hourly check does not call the AccountManager.
        Http::fake(); // only resets the recorded requests
        $this->artisan('households:import --check')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_rejected_proposals_stay_local(): void
    {
        config(['households.driver' => 'local']);
        $family = $this->localHousehold('Familie', [[$this->owner, 'admin'], [$this->member, 'member']]);
        $listId = $this->sharedList($this->owner, $family);
        config(['households.driver' => 'account-manager']);
        $family->forceFill(['account_manager_import_status' => 'pending'])->save();
        $this->accountManager['GET households/import'] = [200, ['data' => [
            ['local_id' => (string) $family->id, 'status' => 'rejected', 'household_uuid' => null, 'household' => null],
        ]]];

        $this->artisan('households:import --check')->assertSuccessful();

        $this->assertSame('rejected', $family->fresh()->account_manager_import_status);
        $this->assertNull($family->fresh()->account_manager_household_uuid);
        $this->assertFalse($this->listDoc($this->member, $listId)['_deleted']);
        $this->actingAs($this->owner)->get('/households')->assertOk()
            ->assertSee('Only in this app, not managed in your account.')
            ->assertSee('Not taken over into your account.');
    }

    public function test_shares_are_reported_to_the_account_manager(): void
    {
        config(['households.account_manager.report_shares' => true]);
        $household = $this->mirror($this->snapshot(1));
        $shares = [];
        $this->accountManager['PUT households/'.self::UUID.'/shares'] = function (HttpRequest $request) use (&$shares) {
            $shares[] = $request->data()['shares'];

            return Http::response(['data' => ['shares' => $request->data()['shares']]]);
        };

        $listId = $this->sharedList($this->owner, $household);
        $list = $this->listDoc($this->owner, $listId);
        $this->push($this->owner, 'lists', [['assumedMasterState' => $list, 'newDocumentState' => [...$list, 'name' => 'Coop', 'version' => $list['version'] + 1]]])->assertExactJson([]);
        $this->actingAs($this->owner)->deleteJson("/lists/{$listId}/households/{$household->uuid}")->assertNoContent();

        $this->assertSame([
            [['resource_type' => 'shopping_list', 'resource_id' => $listId, 'label' => 'Wocheneinkauf']],
            [['resource_type' => 'shopping_list', 'resource_id' => $listId, 'label' => 'Coop']],
            [],
        ], $shares);

        // The overview is optional: failures do not break sharing, the daily sync reports again.
        $this->accountManager['PUT households/'.self::UUID.'/shares'] = [500, ['message' => 'Server Error']];
        $this->actingAs($this->owner)->postJson("/lists/{$listId}/households", ['household' => $household->uuid])->assertNoContent();
        $this->assertTrue(ShoppingList::find($listId)->households()->whereKey($household->id)->exists());
        $this->assertSame(hash('sha256', '[]'), $household->fresh()->account_manager_shares_hash);

        // Reported with the next change; deleting the list removes it from the overview.
        $shares = [];
        $this->accountManager['PUT households/'.self::UUID.'/shares'] = function (HttpRequest $request) use (&$shares) {
            $shares[] = $request->data()['shares'];

            return Http::response(['data' => ['shares' => $request->data()['shares']]]);
        };
        $this->actingAs($this->owner)->postJson("/lists/{$listId}/households", ['household' => $household->uuid])->assertNoContent();
        $list = $this->listDoc($this->owner, $listId);
        $this->push($this->owner, 'lists', [['assumedMasterState' => $list, 'newDocumentState' => [...$list, '_deleted' => true, 'version' => $list['version'] + 1]]])->assertExactJson([]);
        $this->assertSame([[['resource_type' => 'shopping_list', 'resource_id' => $listId, 'label' => 'Coop']], []], $shares);
    }

    public function test_the_households_pages_link_to_the_account_manager(): void
    {
        $household = $this->mirror($this->snapshot(1, ['sub-owner' => 'admin', 'sub-new' => 'member', 'sub-other' => 'member']));

        $this->actingAs($this->owner)->get('/households')->assertOk()
            ->assertSee('Familie Muster')
            ->assertSee('https://account-manager.test/households')
            ->assertDontSee('Create household');
        $this->actingAs($this->owner)->get(route('households.show', $household))->assertOk()
            ->assertSee('https://account-manager.test/households')
            ->assertSee('2 more members have not used the app yet.')
            ->assertDontSee(route('households.invitations.store', $household));
        $this->actingAs($this->owner)->post('/households', ['name' => 'Neu'])->assertForbidden();
        $this->actingAs($this->owner)->delete(route('households.members.destroy', [$household, $this->owner]))->assertForbidden();
    }
}
