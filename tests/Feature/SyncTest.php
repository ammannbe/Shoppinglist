<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Product;
use App\Models\ShoppingList;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SyncTest extends TestCase
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

    private function createList(User $user, string $name = 'Migros'): string
    {
        $id = (string) Str::uuid();
        $this->push($user, 'lists', [['newDocumentState' => ['id' => $id, 'name' => $name]]])
            ->assertOk()->assertExactJson([]);

        return $id;
    }

    private function createItem(User $user, string $listId, array $attributes = []): string
    {
        $id = (string) Str::uuid();
        $this->push($user, 'items', [['newDocumentState' => [
            'id' => $id, 'shopping_list_id' => $listId, 'name' => 'Milch', 'amount' => 2, 'unit' => 'l', 'done' => false,
            ...$attributes,
        ]]])->assertOk()->assertExactJson([]);

        return $id;
    }

    public function test_guests_and_unverified_users_cannot_sync(): void
    {
        $this->getJson('/sync/lists/pull')->assertUnauthorized();

        $this->actingAs(User::factory()->unverified()->create())
            ->getJson('/sync/lists/pull')->assertForbidden();
    }

    public function test_unknown_collections_are_not_found(): void
    {
        $this->pull(User::factory()->create(), 'users')->assertNotFound();
    }

    public function test_created_lists_and_items_are_pulled(): void
    {
        $user = User::factory()->create();
        $listId = $this->createList($user);
        $itemId = $this->createItem($user, $listId);

        $this->pull($user, 'lists')->assertOk()
            ->assertJsonPath('documents.0.id', $listId)
            ->assertJsonPath('documents.0.is_owner', true)
            ->assertJsonPath('documents.0.version', 1)
            ->assertJsonPath('documents.0._deleted', false);

        $response = $this->pull($user, 'items')->assertOk()
            ->assertJsonPath('documents.0.id', $itemId)
            ->assertJsonPath('documents.0.amount', 2)
            ->assertJsonPath('documents.0.creator_email', $user->email);

        // Nothing new after the returned checkpoint.
        $this->pull($user, 'items', $response->json('checkpoint'))
            ->assertJsonCount(0, 'documents')
            ->assertJsonPath('checkpoint', $response->json('checkpoint'));
    }

    public function test_pull_pages_through_documents_with_the_same_timestamp(): void
    {
        $user = User::factory()->create();
        $listId = $this->createList($user);
        foreach (range(1, 5) as $i) {
            $this->createItem($user, $listId, ['name' => "Item {$i}"]);
        }
        Item::query()->update(['updated_at' => '2026-01-01 10:00:00.000']);

        $seen = [];
        $checkpoint = [];
        do {
            $response = $this->pull($user, 'items', [...$checkpoint, 'limit' => 2])->assertOk();
            $seen = [...$seen, ...array_column($response->json('documents'), 'id')];
            $checkpoint = $response->json('checkpoint');
        } while (count($response->json('documents')) > 0);

        $this->assertCount(5, array_unique($seen));
    }

    public function test_updates_increase_the_version(): void
    {
        $user = User::factory()->create();
        $listId = $this->createList($user);
        $itemId = $this->createItem($user, $listId);
        $doc = $this->pull($user, 'items')->json('documents.0');

        $this->push($user, 'items', [[
            'assumedMasterState' => $doc,
            'newDocumentState' => [...$doc, 'done' => true],
        ]])->assertOk()->assertExactJson([]);

        $item = Item::find($itemId);
        $this->assertTrue($item->done);
        $this->assertSame(2, $item->version);
    }

    public function test_the_client_version_is_kept_so_follow_up_pushes_do_not_conflict(): void
    {
        $user = User::factory()->create();
        $listId = $this->createList($user);
        $itemId = $this->createItem($user, $listId);
        $master = $this->pull($user, 'items')->json('documents.0');

        // Three offline edits are pushed as one row, RxDB then assumes the pushed state as master.
        $pushed = [...$master, 'name' => 'Hafermilch', 'version' => 4];
        $this->push($user, 'items', [['assumedMasterState' => $master, 'newDocumentState' => $pushed]])
            ->assertExactJson([]);
        $this->push($user, 'items', [['assumedMasterState' => $pushed, 'newDocumentState' => [...$pushed, 'done' => true, 'version' => 5]]])
            ->assertExactJson([]);

        $item = Item::find($itemId);
        $this->assertSame([5, 'Hafermilch', true], [$item->version, $item->name, $item->done]);
    }

    public function test_stale_updates_return_the_server_document_as_conflict(): void
    {
        $user = User::factory()->create();
        $listId = $this->createList($user);
        $itemId = $this->createItem($user, $listId);
        $doc = $this->pull($user, 'items')->json('documents.0');

        // Another device changed the item in the meantime.
        Item::find($itemId)->forceFill(['name' => 'Hafermilch', 'version' => 2])->save();

        $this->push($user, 'items', [[
            'assumedMasterState' => $doc,
            'newDocumentState' => [...$doc, 'name' => 'Vollmilch'],
        ]])->assertOk()
            ->assertJsonPath('0.id', $itemId)
            ->assertJsonPath('0.name', 'Hafermilch')
            ->assertJsonPath('0.version', 2);

        $this->assertSame('Hafermilch', Item::find($itemId)->name);
    }

    public function test_deleted_items_are_pulled_as_deleted(): void
    {
        $user = User::factory()->create();
        $listId = $this->createList($user);
        $itemId = $this->createItem($user, $listId);
        $doc = $this->pull($user, 'items')->json('documents.0');

        $this->push($user, 'items', [[
            'assumedMasterState' => $doc,
            'newDocumentState' => [...$doc, '_deleted' => true],
        ]])->assertOk()->assertExactJson([]);

        $this->assertSoftDeleted('items', ['id' => $itemId]);
        $this->pull($user, 'items')->assertJsonPath('documents.0._deleted', true);
    }

    public function test_items_of_unknown_lists_are_retried_later(): void
    {
        $user = User::factory()->create();

        $this->push($user, 'items', [['newDocumentState' => [
            'id' => (string) Str::uuid(), 'shopping_list_id' => (string) Str::uuid(), 'name' => 'Brot',
        ]]])->assertStatus(409);

        $this->assertDatabaseCount('items', 0);
    }

    public function test_foreign_lists_and_items_are_neither_visible_nor_writable(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $listId = $this->createList($owner);
        $itemId = $this->createItem($owner, $listId);

        $this->pull($stranger, 'lists')->assertJsonCount(0, 'documents');
        $this->pull($stranger, 'items')->assertJsonCount(0, 'documents');

        $doc = $this->pull($owner, 'items')->json('documents.0');
        $this->push($stranger, 'items', [[
            'assumedMasterState' => $doc,
            'newDocumentState' => [...$doc, 'name' => 'Hacked'],
        ]])->assertOk()->assertExactJson([]);
        $this->push($stranger, 'items', [['newDocumentState' => [
            'id' => (string) Str::uuid(), 'shopping_list_id' => $listId, 'name' => 'Injected',
        ]]])->assertOk();
        $this->push($stranger, 'lists', [[
            'assumedMasterState' => ['version' => 1],
            'newDocumentState' => ['id' => $listId, 'name' => 'Hacked'],
        ]])->assertOk();

        $this->assertSame('Milch', Item::find($itemId)->name);
        $this->assertDatabaseCount('items', 1);
        $this->assertSame('Migros', ShoppingList::find($listId)->name);
    }

    public function test_members_see_shared_lists_with_existing_items(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $listId = $this->createList($owner);
        $this->createItem($owner, $listId);

        // The member already synced before the list was shared.
        $checkpoint = $this->pull($member, 'items')->json('checkpoint');
        $this->travel(1)->seconds();
        ShoppingList::find($listId)->addMember($member);

        $this->pull($member, 'lists')
            ->assertJsonPath('documents.0.id', $listId)
            ->assertJsonPath('documents.0.is_owner', false)
            ->assertJsonPath('documents.0.owner_email', $owner->email);
        $this->pull($member, 'items', $checkpoint)->assertJsonCount(1, 'documents');
        $this->pull($owner, 'lists')->assertJsonPath('documents.0.members', [$member->email]);
    }

    public function test_owners_delete_lists_for_everyone(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $listId = $this->createList($owner);
        ShoppingList::find($listId)->addMember($member);
        $doc = $this->pull($owner, 'lists')->json('documents.0');

        $this->push($owner, 'lists', [[
            'assumedMasterState' => $doc,
            'newDocumentState' => [...$doc, '_deleted' => true],
        ]])->assertOk()->assertExactJson([]);

        $this->assertSoftDeleted('shopping_lists', ['id' => $listId]);
        $this->pull($member, 'lists')->assertJsonPath('documents.0._deleted', true);
        $this->pull($member, 'items')->assertJsonCount(0, 'documents');
    }

    public function test_members_leave_lists_instead_of_deleting_them(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $listId = $this->createList($owner);
        ShoppingList::find($listId)->addMember($member);
        $doc = $this->pull($member, 'lists')->json('documents.0');

        $this->push($member, 'lists', [[
            'assumedMasterState' => $doc,
            'newDocumentState' => [...$doc, '_deleted' => true],
        ]])->assertOk();

        $this->assertNotSoftDeleted('shopping_lists', ['id' => $listId]);
        $this->pull($member, 'lists')->assertJsonPath('documents.0._deleted', true);
        $this->pull($owner, 'lists')->assertJsonPath('documents.0.members', []);
    }

    public function test_public_products_are_read_only(): void
    {
        $user = User::factory()->create();
        $public = new Product(['name' => 'Brot']);
        $public->save();

        $doc = $this->pull($user, 'products')->assertJsonPath('documents.0.is_public', true)->json('documents.0');

        $this->push($user, 'products', [[
            'assumedMasterState' => $doc,
            'newDocumentState' => [...$doc, 'name' => 'Toast'],
        ]])->assertJsonPath('0.name', 'Brot');

        $ownId = (string) Str::uuid();
        $this->push($user, 'products', [['newDocumentState' => ['id' => $ownId, 'name' => 'Tofu']]])
            ->assertExactJson([]);
        $this->assertSame($user->id, Product::find($ownId)->owner_id);
    }

    public function test_public_units_are_read_only_and_own_units_are_private(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $unit = Unit::create(['name' => 'kg']);

        $doc = $this->pull($user, 'units')
            ->assertJsonPath('documents.0.name', 'kg')
            ->assertJsonPath('documents.0.is_public', true)
            ->json('documents.0');

        $this->push($user, 'units', [['assumedMasterState' => $doc, 'newDocumentState' => [...$doc, 'name' => 'g']]])
            ->assertJsonPath('0.name', 'kg');
        $this->assertSame('kg', $unit->fresh()->name);

        // Own units may share the name of another user's unit.
        $ownId = (string) Str::uuid();
        $this->push($user, 'units', [['newDocumentState' => ['id' => $ownId, 'name' => 'Becher']]])->assertExactJson([]);
        $this->push($other, 'units', [['newDocumentState' => ['id' => (string) Str::uuid(), 'name' => 'Becher']]])->assertExactJson([]);

        $this->assertSame($user->id, Unit::find($ownId)->owner_id);
        $this->pull($user, 'units')->assertJsonCount(2, 'documents')->assertJsonPath('documents.1.is_public', false);
    }

    public function test_store_and_note_of_items_are_synced(): void
    {
        $user = User::factory()->create();
        $listId = $this->createList($user);
        $this->createItem($user, $listId, ['store' => 'M', 'note' => 'Aktion −30 %']);

        $this->pull($user, 'items')
            ->assertJsonPath('documents.0.store', 'M')
            ->assertJsonPath('documents.0.note', 'Aktion −30 %');
    }

    public function test_item_positions_are_shared_and_list_positions_are_personal(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $listId = $this->createList($owner);
        ShoppingList::find($listId)->addMember($member);
        $this->createItem($owner, $listId, ['position' => 2.5]);

        $this->pull($member, 'items')->assertJsonPath('documents.0.position', 2.5);

        $doc = $this->pull($member, 'lists')->json('documents.0');
        $this->push($member, 'lists', [['assumedMasterState' => $doc, 'newDocumentState' => [...$doc, 'version' => $doc['version'] + 1, 'position' => 3]]])
            ->assertExactJson([]);

        $this->pull($member, 'lists')->assertJsonPath('documents.0.position', 3);
        $this->pull($owner, 'lists')->assertJsonPath('documents.0.position', null);
    }
}
