<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\PlannedRecipe;
use App\Models\ShoppingList;
use App\Models\User;
use App\Notifications\HouseholdInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HouseholdTest extends TestCase
{
    use RefreshDatabase;

    private function push(User $user, string $collection, array $rows): TestResponse
    {
        return $this->actingAs($user)->postJson("/sync/{$collection}/push", $rows);
    }

    private function pull(User $user, string $collection, array $checkpoint = []): TestResponse
    {
        return $this->actingAs($user)->getJson("/sync/{$collection}/pull?".http_build_query($checkpoint));
    }

    private function createList(User $user, string $name = 'Wocheneinkauf'): ShoppingList
    {
        $id = (string) Str::uuid();
        $this->push($user, 'lists', [['newDocumentState' => ['id' => $id, 'name' => $name]]])
            ->assertOk()->assertExactJson([]);

        return ShoppingList::find($id);
    }

    private function createItem(User $user, ShoppingList $list, string $name = 'Milch'): string
    {
        $id = (string) Str::uuid();
        $this->push($user, 'items', [['newDocumentState' => ['id' => $id, 'shopping_list_id' => $list->id, 'name' => $name]]])
            ->assertOk()->assertExactJson([]);

        return $id;
    }

    private function household(User $admin, string $name = 'Familie Muster'): Household
    {
        $household = new Household(['name' => $name]);
        $household->creator()->associate($admin)->save();
        $household->addMember($admin, 'admin');

        return $household;
    }

    /**
     * Owner with a list shared with a household that has one more member.
     *
     * @return array{0: User, 1: User, 2: Household, 3: ShoppingList}
     */
    private function sharedList(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($owner);
        $household->addMember($member);
        $list = $this->createList($owner);
        $this->actingAs($owner)->postJson("/lists/{$list->id}/households", ['household' => $household->uuid])->assertNoContent();

        return [$owner, $member, $household, $list];
    }

    private function listDocument(User $user, ShoppingList $list): ?array
    {
        return collect($this->pull($user, 'lists')->json('documents'))->firstWhere('id', $list->id);
    }

    // ---- access through the household

    public function test_household_members_get_shared_lists_with_existing_items(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($owner);
        $household->addMember($member);
        $list = $this->createList($owner);
        $this->createItem($owner, $list);

        // The member already synced before the list was shared.
        $checkpoint = $this->pull($member, 'items')->json('checkpoint');
        $this->travel(1)->seconds();
        $this->actingAs($owner)->postJson("/lists/{$list->id}/households", ['household' => $household->uuid])->assertNoContent();

        $doc = $this->listDocument($member, $list);
        $this->assertFalse($doc['_deleted']);
        $this->assertFalse($doc['is_owner']);
        $this->assertSame([['id' => $household->uuid, 'name' => 'Familie Muster']], $doc['households']);
        $this->pull($member, 'items', $checkpoint)->assertJsonCount(1, 'documents');

        // Members edit the list like members shared by email.
        $this->createItem($member, $list, 'Brot');
        $this->assertSame(2, $list->items()->count());

        $ownerDoc = $this->listDocument($owner, $list);
        $this->assertSame([], $ownerDoc['members']);
        $this->assertSame('Familie Muster', $ownerDoc['households'][0]['name']);
    }

    public function test_non_members_do_not_get_household_lists(): void
    {
        [, , , $list] = $this->sharedList();
        $stranger = User::factory()->create();
        $this->household($stranger, 'Andere');

        $this->assertNull($this->listDocument($stranger, $list));
        $this->pull($stranger, 'items')->assertJsonCount(0, 'documents');
        $this->push($stranger, 'items', [['newDocumentState' => ['id' => (string) Str::uuid(), 'shopping_list_id' => $list->id, 'name' => 'Hack']]])
            ->assertOk();
        $this->assertSame(0, $list->items()->count());
    }

    public function test_members_joining_later_get_the_shared_lists(): void
    {
        [$owner, , $household, $list] = $this->sharedList();
        $this->createItem($owner, $list);
        $later = User::factory()->create();
        $checkpoint = $this->pull($later, 'items')->json('checkpoint');
        $this->travel(1)->seconds();

        $household->addMember($later);

        $this->assertFalse($this->listDocument($later, $list)['_deleted']);
        $this->pull($later, 'items', $checkpoint)->assertJsonCount(1, 'documents');
    }

    public function test_removed_members_lose_the_lists_on_the_next_sync_and_cannot_push(): void
    {
        [$owner, $member, $household, $list] = $this->sharedList();
        $itemId = $this->createItem($owner, $list);
        $listDoc = $this->listDocument($member, $list);
        $itemDoc = $this->pull($member, 'items')->json('documents.0');
        $listCheckpoint = $this->pull($member, 'lists')->json('checkpoint');
        $this->travel(1)->seconds();

        $this->actingAs($owner)->delete(route('households.members.destroy', [$household, $member]))->assertRedirect();

        // The list arrives as deleted document, its items are not pulled anymore.
        $this->pull($member, 'lists', $listCheckpoint)
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.id', $list->id)
            ->assertJsonPath('documents.0._deleted', true);
        $this->pull($member, 'items')->assertJsonCount(0, 'documents');

        // Pushes of the removed member (made offline) are ignored.
        $this->push($member, 'items', [['assumedMasterState' => $itemDoc, 'newDocumentState' => [...$itemDoc, 'name' => 'Hack', 'version' => 2]]])->assertOk();
        $this->push($member, 'items', [['newDocumentState' => ['id' => (string) Str::uuid(), 'shopping_list_id' => $list->id, 'name' => 'Neu']]])->assertOk();
        $this->push($member, 'lists', [['assumedMasterState' => $listDoc, 'newDocumentState' => [...$listDoc, 'name' => 'Meins', 'version' => 2]]])->assertOk();

        $this->assertSame('Milch', $list->items()->find($itemId)->name);
        $this->assertSame(1, $list->items()->count());
        $this->assertSame('Wocheneinkauf', $list->fresh()->name);
        // The owner keeps the list.
        $this->assertFalse($this->listDocument($owner, $list)['_deleted']);
    }

    public function test_members_leaving_lose_the_lists(): void
    {
        [, $member, $household, $list] = $this->sharedList();

        $this->actingAs($member)->delete(route('households.members.destroy', [$household, $member]))
            ->assertRedirect(route('households.index'));

        $this->assertFalse($household->hasMember($member));
        $this->assertTrue($this->listDocument($member, $list)['_deleted']);
    }

    public function test_removed_members_lose_access_only_through_the_household(): void
    {
        [$owner, $member, $household, $list] = $this->sharedList();
        // Also shared by email.
        $list->addMember($member);

        $household->removeMember($member);
        $this->assertFalse($this->listDocument($member, $list)['_deleted']);
        $this->assertTrue($member->accessibleListIds()->whereKey($list->id)->exists());

        // The other way round: removed by email, access through the household stays.
        $household->addMember($member);
        $this->actingAs($owner)->deleteJson("/lists/{$list->id}/members/{$member->email}")->assertNoContent();
        $this->assertFalse($list->hasMember($member));
        $this->assertFalse($this->listDocument($member, $list)['_deleted']);
        $this->assertSame([], $this->listDocument($owner, $list)['members']);
    }

    public function test_household_members_cannot_leave_a_list_but_keep_it(): void
    {
        [, $member, , $list] = $this->sharedList();
        $doc = $this->listDocument($member, $list);

        $this->push($member, 'lists', [['assumedMasterState' => $doc, 'newDocumentState' => [...$doc, '_deleted' => true, 'version' => 2]]])
            ->assertOk()
            ->assertJsonPath('0.id', $list->id)
            ->assertJsonPath('0._deleted', false);

        $this->assertFalse($this->listDocument($member, $list)['_deleted']);
    }

    public function test_unsharing_removes_the_access(): void
    {
        [$owner, $member, $household, $list] = $this->sharedList();

        $this->actingAs($member)->deleteJson("/lists/{$list->id}/households/{$household->uuid}")->assertForbidden();
        $this->actingAs($owner)->deleteJson("/lists/{$list->id}/households/{$household->uuid}")->assertNoContent();

        $this->assertTrue($this->listDocument($member, $list)['_deleted']);
        $this->assertSame([], $this->listDocument($owner, $list)['households']);
    }

    public function test_deleting_the_household_removes_only_the_access(): void
    {
        [$owner, $member, $household, $list] = $this->sharedList();
        $this->createItem($member, $list);

        $this->actingAs($member)->delete(route('households.destroy', $household))->assertForbidden();
        $this->actingAs($owner)->delete(route('households.destroy', $household))->assertRedirect(route('households.index'));

        $this->assertModelMissing($household);
        $this->assertTrue($this->listDocument($member, $list)['_deleted']);
        $this->pull($member, 'items')->assertJsonCount(0, 'documents');
        $this->assertFalse($this->listDocument($owner, $list)['_deleted']);
        $this->pull($owner, 'items')->assertJsonCount(1, 'documents');
    }

    public function test_removing_the_owner_ends_the_sharing_of_their_lists(): void
    {
        [$owner, $member, $household, $list] = $this->sharedList();
        // The member is the second admin, so the owner may leave.
        $household->changeRole($member, 'admin');

        $this->actingAs($owner)->delete(route('households.members.destroy', [$household, $owner]))->assertRedirect();

        $this->assertSame(0, $household->shoppingLists()->count());
        $this->assertTrue($this->listDocument($member, $list)['_deleted']);
        $this->assertFalse($this->listDocument($owner, $list)['_deleted']);
    }

    public function test_only_owners_share_lists_with_their_households(): void
    {
        [$owner, $member, $household, $list] = $this->sharedList();
        $other = User::factory()->create();
        $foreign = $this->household($other, 'Fremd');

        $this->actingAs($member)->postJson("/lists/{$list->id}/households", ['household' => $household->uuid])->assertForbidden();
        // Only households the owner belongs to.
        $this->actingAs($owner)->postJson("/lists/{$list->id}/households", ['household' => $foreign->uuid])->assertForbidden();
        $this->assertNull($this->listDocument($other, $list));
    }

    public function test_me_lists_the_households(): void
    {
        [, $member, $household] = $this->sharedList();

        $this->actingAs($member)->getJson('/me')->assertJsonPath('households', [['id' => $household->uuid, 'name' => 'Familie Muster']]);
    }

    public function test_household_members_see_recipes_linked_in_household_lists(): void
    {
        [$owner, $member, $household, $list] = $this->sharedList();
        $lasagne = PlannedRecipe::forceCreate(['user_id' => $owner->id, 'key' => 'idea-'.Str::uuid(), 'source' => 'ai', 'name' => 'Lasagne', 'data' => ['ingredients' => []]]);
        $link = ['recipes' => [['name' => 'Lasagne', 'url' => null, 'key' => $lasagne->id]]];
        $this->push($owner, 'items', [['newDocumentState' => ['id' => (string) Str::uuid(), 'shopping_list_id' => $list->id, 'name' => 'Hackfleisch', ...$link]]])
            ->assertExactJson([]);

        $this->actingAs($member)->getJson("/planned-recipes/{$lasagne->id}")->assertOk()->assertJsonPath('recipe.shared', true);
        // Members may copy the link into an own list (moving an item).
        $ownList = $this->createList($member, 'Eigene');
        $this->push($member, 'items', [['newDocumentState' => ['id' => (string) Str::uuid(), 'shopping_list_id' => $ownList->id, 'name' => 'Hackfleisch', ...$link]]])
            ->assertExactJson([]);
        $this->assertSame($lasagne->id, $ownList->items()->first()->recipes[0]['key']);

        // Removed from the household: no access through the household list anymore, a link pushed afterwards is dropped.
        $this->actingAs($owner)->delete(route('households.members.destroy', [$household, $member]))->assertRedirect();
        $ownList->items()->forceDelete();
        $this->actingAs($member)->getJson("/planned-recipes/{$lasagne->id}")->assertNotFound();
        $this->push($member, 'items', [['newDocumentState' => ['id' => (string) Str::uuid(), 'shopping_list_id' => $ownList->id, 'name' => 'Hackfleisch', ...$link]]])
            ->assertExactJson([]);
        $this->assertNull($ownList->items()->first()->recipes[0]['key']);
    }

    // ---- management

    public function test_users_create_households_and_become_admin(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('households.store'), ['name' => 'WG Bern']);

        $household = Household::firstWhere('name', 'WG Bern');
        $response->assertRedirect(route('households.show', $household));
        $this->assertTrue($household->isAdmin($user));
        $this->assertTrue($household->creator->is($user));
        $this->actingAs($user)->get(route('households.index'))->assertOk()->assertSee('WG Bern');
        $this->actingAs($user)->get(route('households.show', $household))->assertOk()->assertSee($user->email);
    }

    public function test_only_members_see_a_household(): void
    {
        [, , $household] = $this->sharedList();

        $this->actingAs(User::factory()->create())->get(route('households.show', $household))->assertForbidden();
    }

    public function test_members_cannot_manage_the_household(): void
    {
        [$owner, $member, $household] = $this->sharedList();
        $this->actingAs($member);

        $this->patch(route('households.update', $household), ['name' => 'Neu'])->assertForbidden();
        $this->post(route('households.invitations.store', $household), ['email' => 'x@example.com', 'role' => 'member'])->assertForbidden();
        $this->patch(route('households.members.update', [$household, $owner]), ['role' => 'member'])->assertForbidden();
        $this->delete(route('households.members.destroy', [$household, $owner]))->assertForbidden();
        $this->get(route('households.show', $household))->assertOk()->assertDontSee(__('Delete household'));
    }

    public function test_admins_rename_and_change_roles(): void
    {
        [$owner, $member, $household, $list] = $this->sharedList();
        $checkpoint = $this->pull($member, 'lists')->json('checkpoint');
        $this->travel(1)->seconds();

        $this->actingAs($owner)->patch(route('households.update', $household), ['name' => 'Familie Beispiel'])->assertRedirect();
        $this->actingAs($owner)->patch(route('households.members.update', [$household, $member]), ['role' => 'admin'])->assertRedirect();

        $this->assertSame('Familie Beispiel', $household->fresh()->name);
        $this->assertTrue($household->isAdmin($member));
        // The new name reaches the clients.
        $this->pull($member, 'lists', $checkpoint)->assertJsonPath('documents.0.households.0.name', 'Familie Beispiel');
    }

    public function test_the_last_admin_cannot_be_removed_demoted_or_leave(): void
    {
        [$owner, $member, $household] = $this->sharedList();
        $this->actingAs($owner);

        $this->patch(route('households.members.update', [$household, $owner]), ['role' => 'member'])->assertSessionHasErrors('role');
        $this->delete(route('households.members.destroy', [$household, $owner]))->assertSessionHasErrors('member');
        $this->assertTrue($household->isAdmin($owner));

        // With a second admin it works.
        $this->patch(route('households.members.update', [$household, $member]), ['role' => 'admin']);
        $this->patch(route('households.members.update', [$household, $owner]), ['role' => 'member'])->assertSessionHasNoErrors();
        $this->assertFalse($household->isAdmin($owner));

        // The other admin cannot remove the last admin either.
        $this->actingAs($member)->delete(route('households.members.destroy', [$household, $member]))->assertSessionHasErrors('member');
        $this->assertTrue($household->hasMember($member));
    }

    // ---- invitations

    public function test_admins_invite_by_email_and_the_invitee_accepts(): void
    {
        Notification::fake();
        [$owner, , $household, $list] = $this->sharedList();
        $invitee = User::factory()->unverified()->create(['email' => 'anna@example.com']);

        $this->actingAs($owner)->post(route('households.invitations.store', $household), ['email' => 'Anna@Example.com', 'role' => 'admin'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $token = null;
        Notification::assertSentTo($invitee, HouseholdInvited::class, function (HouseholdInvited $notification) use ($invitee, &$token) {
            $token = Str::afterLast($notification->toMail($invitee)->actionUrl, '/');

            return true;
        });
        // Only the hash is stored.
        $this->assertDatabaseMissing('household_invitations', ['token_hash' => $token]);
        $this->assertDatabaseHas('household_invitations', ['email' => 'anna@example.com', 'token_hash' => hash('sha256', $token)]);

        $this->actingAs($invitee)->get(route('households.invitations.show', $token))->assertOk()->assertSee(__('Join household'));
        $this->actingAs($invitee)->post(route('households.invitations.accept', $token))->assertRedirect(route('households.show', $household));

        $this->assertTrue($household->isAdmin($invitee));
        // The link came by mail to the address.
        $this->assertTrue($invitee->fresh()->hasVerifiedEmail());
        $this->assertFalse($this->listDocument($invitee, $list)['_deleted']);
        // Used once.
        $this->actingAs($invitee)->post(route('households.invitations.accept', $token))->assertForbidden();
    }

    public function test_unknown_addresses_are_invited_by_mail(): void
    {
        Notification::fake();
        [$owner, , $household] = $this->sharedList();

        $this->actingAs($owner)->post(route('households.invitations.store', $household), ['email' => 'neu@example.com', 'role' => 'member'])
            ->assertSessionHasNoErrors();

        $this->assertNull(User::firstWhere('email', 'neu@example.com'));
        Notification::assertSentOnDemand(HouseholdInvited::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'neu@example.com');
    }

    public function test_members_are_not_invited_again(): void
    {
        [$owner, $member, $household] = $this->sharedList();

        $this->actingAs($owner)->post(route('households.invitations.store', $household), ['email' => $member->email, 'role' => 'member'])
            ->assertSessionHasErrors('email');
    }

    public function test_guests_log_in_first_and_come_back(): void
    {
        [$owner, , $household] = $this->sharedList();
        [, $token] = HouseholdInvitation::issue($household, $owner, 'gast@example.com', 'member');
        $this->actingAsGuest();

        $this->get(route('households.invitations.show', $token))->assertOk()
            ->assertSee('gast@example.com')
            ->assertSessionHas('url.intended', route('households.invitations.show', $token));
        $this->post(route('households.invitations.accept', $token))->assertRedirect(route('login'));
    }

    public function test_invitations_only_work_for_the_invited_address(): void
    {
        [$owner, , $household] = $this->sharedList();
        [, $token] = HouseholdInvitation::issue($household, $owner, 'anna@example.com', 'member');
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('households.invitations.show', $token))->assertOk()->assertDontSee(__('Join household'));
        $this->actingAs($other)->post(route('households.invitations.accept', $token))->assertForbidden();
        $this->assertFalse($household->hasMember($other));
    }

    public function test_expired_withdrawn_and_unknown_invitations_cannot_be_accepted(): void
    {
        [$owner, , $household] = $this->sharedList();
        $anna = User::factory()->create(['email' => 'anna@example.com']);
        [, $token] = HouseholdInvitation::issue($household, $owner, 'anna@example.com', 'member');

        $this->travel(config('households.invitation_days') + 1)->days();
        $this->actingAs($anna)->get(route('households.invitations.show', $token))->assertOk()->assertSee(__('This invitation has expired. Ask for a new one.'));
        $this->actingAs($anna)->post(route('households.invitations.accept', $token))->assertForbidden();

        [$invitation, $token] = HouseholdInvitation::issue($household, $owner, 'anna@example.com', 'member');
        $this->actingAs($owner)->delete(route('households.invitations.destroy', [$household, $invitation]))->assertRedirect();
        $this->actingAs($anna)->post(route('households.invitations.accept', $token))->assertForbidden();
        $this->actingAs($anna)->post(route('households.invitations.accept', 'unknown'))->assertForbidden();

        $this->assertFalse($household->hasMember($anna));
    }

    public function test_a_new_invitation_replaces_the_open_one(): void
    {
        [$owner, , $household] = $this->sharedList();
        [, $first] = HouseholdInvitation::issue($household, $owner, 'anna@example.com', 'member');
        HouseholdInvitation::issue($household, $owner, 'anna@example.com', 'admin');

        $this->assertNull(HouseholdInvitation::findByToken($first));
        $this->assertSame(1, $household->invitations()->count());
    }

    // ---- HOUSEHOLDS_DRIVER=account-manager

    public function test_the_account_manager_driver_makes_the_local_management_read_only(): void
    {
        [$owner, $member, $household, $list] = $this->sharedList();
        [, $token] = HouseholdInvitation::issue($household, $owner, 'anna@example.com', 'member');
        $anna = User::factory()->create(['email' => 'anna@example.com']);
        config(['households.driver' => 'account-manager']);

        $this->actingAs($owner);
        $this->post(route('households.store'), ['name' => 'Neu'])->assertForbidden();
        $this->patch(route('households.update', $household), ['name' => 'Neu'])->assertForbidden();
        $this->delete(route('households.destroy', $household))->assertForbidden();
        $this->post(route('households.invitations.store', $household), ['email' => 'x@example.com', 'role' => 'member'])->assertForbidden();
        $this->patch(route('households.members.update', [$household, $member]), ['role' => 'admin'])->assertForbidden();
        $this->delete(route('households.members.destroy', [$household, $member]))->assertForbidden();
        $this->actingAs($member)->delete(route('households.members.destroy', [$household, $member]))->assertForbidden();
        $this->actingAs($anna)->post(route('households.invitations.accept', $token))->assertForbidden();

        // Viewing and sharing lists keep working.
        $this->actingAs($owner)->get(route('households.show', $household))->assertOk()
            ->assertSee(__('Households are managed in your account at the provider. Here they can only be viewed.'));
        $this->actingAs($owner)->deleteJson("/lists/{$list->id}/households/{$household->uuid}")->assertNoContent();
        $this->actingAs($owner)->postJson("/lists/{$list->id}/households", ['household' => $household->uuid])->assertNoContent();
        $this->assertSame(2, $household->members()->count());
        $this->assertFalse($this->listDocument($member, $list)['_deleted']);
    }
}
