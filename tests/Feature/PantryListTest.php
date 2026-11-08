<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Pantry lists: shopping lists with `kind` pantry, synced and shared like any list.
 */
class PantryListTest extends TestCase
{
    use RefreshDatabase;

    private function push(User $user, string $collection, array $rows): TestResponse
    {
        return $this->actingAs($user)->postJson("/sync/{$collection}/push", $rows);
    }

    private function listDocument(User $user, string $id): ?array
    {
        return collect($this->actingAs($user)->getJson('/sync/lists/pull')->json('documents'))->firstWhere('id', $id);
    }

    private function createList(User $user, array $attributes = []): string
    {
        $id = (string) Str::uuid();
        $this->push($user, 'lists', [['newDocumentState' => ['id' => $id, 'name' => 'Vorrat', ...$attributes]]])
            ->assertOk()->assertExactJson([]);

        return $id;
    }

    /**
     * Pushes a change of the list document as pulled by the user.
     */
    private function update(User $user, string $id, array $changes, ?callable $without = null): TestResponse
    {
        $doc = $this->listDocument($user, $id);
        $new = [...$doc, ...$changes, 'version' => $doc['version'] + 1];

        return $this->push($user, 'lists', [['assumedMasterState' => $doc, 'newDocumentState' => $without ? $without($new) : $new]]);
    }

    public function test_the_kind_is_set_on_creation_and_pulled(): void
    {
        $user = User::factory()->create();
        $pantry = $this->createList($user, ['kind' => 'pantry']);
        $shopping = $this->createList($user, ['kind' => 'shopping']);

        $this->assertSame('pantry', $this->listDocument($user, $pantry)['kind']);
        $this->assertSame('shopping', $this->listDocument($user, $shopping)['kind']);
    }

    public function test_lists_of_older_clients_are_shopping_lists_and_keep_their_kind(): void
    {
        $user = User::factory()->create();

        // Created by a client from before the pantry lists: no kind.
        $old = $this->createList($user);
        $this->assertSame('shopping', ShoppingList::find($old)->kind);
        $this->assertSame('shopping', $this->listDocument($user, $old)['kind']);

        // An older client renames a pantry without knowing the kind.
        $pantry = $this->createList($user, ['kind' => 'pantry']);
        $this->update($user, $pantry, ['name' => 'Keller'], fn (array $doc) => collect($doc)->except('kind')->all())
            ->assertOk()->assertExactJson([]);

        $this->assertSame('Keller', ShoppingList::find($pantry)->name);
        $this->assertSame('pantry', ShoppingList::find($pantry)->kind);
    }

    public function test_only_the_owner_changes_the_kind(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $id = $this->createList($owner, ['kind' => 'pantry']);
        ShoppingList::find($id)->addMember($member);

        // A member's push keeps the kind (the name changes like before).
        $this->update($member, $id, ['name' => 'Küche', 'kind' => 'shopping'])->assertOk()->assertExactJson([]);
        $this->assertSame('pantry', ShoppingList::find($id)->kind);
        $this->assertSame('Küche', ShoppingList::find($id)->name);
        $this->assertSame('pantry', $this->listDocument($member, $id)['kind']);

        $this->update($owner, $id, ['kind' => 'shopping'])->assertOk()->assertExactJson([]);
        $this->assertSame('shopping', ShoppingList::find($id)->kind);
        $this->assertSame('shopping', $this->listDocument($member, $id)['kind']);
    }

    public function test_the_kind_is_validated(): void
    {
        $user = User::factory()->create();

        $this->push($user, 'lists', [['newDocumentState' => ['id' => (string) Str::uuid(), 'name' => 'Vorrat', 'kind' => 'fridge']]])
            ->assertUnprocessable()->assertJsonValidationErrors('0.newDocumentState.kind');
        $this->assertSame(0, ShoppingList::count());

        $id = $this->createList($user, ['kind' => 'pantry']);
        $this->update($user, $id, ['kind' => 'cellar'])->assertUnprocessable();
        $this->assertSame('pantry', ShoppingList::find($id)->kind);
    }

    public function test_household_members_see_a_shared_pantry_with_its_items(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $household = new Household(['name' => 'Familie Muster']);
        $household->creator()->associate($owner)->save();
        $household->addMember($owner, 'admin');
        $household->addMember($member);

        $id = $this->createList($owner, ['kind' => 'pantry']);
        $itemId = (string) Str::uuid();
        $this->push($owner, 'items', [['newDocumentState' => ['id' => $itemId, 'shopping_list_id' => $id, 'name' => 'Reis', 'done' => false]]])
            ->assertOk()->assertExactJson([]);
        $this->actingAs($owner)->postJson("/lists/{$id}/households", ['household' => $household->uuid])->assertNoContent();

        $doc = $this->listDocument($member, $id);
        $this->assertSame('pantry', $doc['kind']);
        $this->assertFalse($doc['is_owner']);
        $this->actingAs($member)->getJson('/sync/items/pull')->assertJsonPath('documents.0.id', $itemId);

        // Members mark items used up like done items.
        $item = $this->actingAs($member)->getJson('/sync/items/pull')->json('documents.0');
        $this->push($member, 'items', [['assumedMasterState' => $item, 'newDocumentState' => [...$item, 'done' => true, 'version' => $item['version'] + 1]]])
            ->assertOk()->assertExactJson([]);
        $this->actingAs($owner)->getJson('/sync/items/pull')->assertJsonPath('documents.0.done', true);
    }
}
