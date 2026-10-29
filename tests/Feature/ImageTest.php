<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Product;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
    }

    private function upload(User $user, string $productId, string $name = 'Milch', ?UploadedFile $file = null)
    {
        return $this->actingAs($user)->post("/products/{$productId}/image", [
            'name' => $name,
            'image' => $file ?? UploadedFile::fake()->image('milk.jpg', 1200, 900),
            'thumb' => UploadedFile::fake()->image('thumb.jpg', 240, 180),
        ], ['Accept' => 'application/json']);
    }

    private function createList(User $owner, string $name): ShoppingList
    {
        $list = new ShoppingList(['name' => $name]);
        $list->owner()->associate($owner)->save();
        $list->addMember($owner);

        return $list;
    }

    public function test_uploading_creates_the_product_and_syncs_the_image(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::uuid();

        $image = $this->upload($user, $id)->assertOk()->json('image');

        Storage::assertExists(["images/{$image}", "images/{$image}-thumb"]);
        $this->assertSame($user->id, Product::find($id)->owner_id);
        $this->actingAs($user)->getJson('/sync/products/pull')->assertJsonPath('documents.0.image', $image);
        $this->actingAs($user)->get("/images/{$image}")->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');
        $this->actingAs($user)->get("/images/{$image}/thumb")->assertOk();
        $this->actingAs($user)->get("/images/{$image}/large")->assertNotFound();
    }

    public function test_replacing_deletes_the_unused_old_image(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::uuid();
        $old = $this->upload($user, $id)->json('image');

        $new = $this->upload($user, $id)->json('image');

        $this->assertNotSame($old, $new);
        Storage::assertMissing(["images/{$old}", "images/{$old}-thumb"]);
        $this->actingAs($user)->deleteJson("/products/{$id}/image")->assertJsonPath('image', null);
        Storage::assertMissing("images/{$new}");
    }

    public function test_members_see_images_of_items_on_shared_lists_only(): void
    {
        [$owner, $member, $stranger] = User::factory()->count(3)->create();
        $image = $this->upload($owner, (string) Str::uuid())->json('image');

        $list = $this->createList($owner, 'Migros');
        $list->addMember($member);
        $this->actingAs($owner)->postJson('/sync/items/push', [['newDocumentState' => [
            'id' => (string) Str::uuid(), 'shopping_list_id' => $list->id, 'name' => 'Milch', 'image' => $image,
        ]]])->assertExactJson([]);

        $this->actingAs($member)->get("/images/{$image}")->assertOk();
        $this->actingAs($member)->get("/images/{$image}/thumb")->assertOk();
        $this->actingAs($stranger)->get("/images/{$image}")->assertNotFound();
        $this->actingAs($stranger)->get("/images/{$image}/thumb")->assertNotFound();
    }

    public function test_foreign_images_cannot_be_attached_to_items(): void
    {
        [$owner, $other] = User::factory()->count(2)->create();
        $image = $this->upload($owner, (string) Str::uuid())->json('image');
        $list = $this->createList($other, 'Coop');
        $itemId = (string) Str::uuid();

        $this->actingAs($other)->postJson('/sync/items/push', [['newDocumentState' => [
            'id' => $itemId, 'shopping_list_id' => $list->id, 'name' => 'Milch', 'image' => $image,
        ]]])->assertExactJson([]);

        $this->assertNull(Item::find($itemId)->image);
        $this->actingAs($other)->get("/images/{$image}")->assertNotFound();
    }

    public function test_older_images_without_thumbnail_serve_the_full_image(): void
    {
        $user = User::factory()->create();
        $image = (string) Str::uuid();
        Storage::put("images/{$image}", 'full');
        $product = new Product(['name' => 'Brot']);
        $product->owner()->associate($user);
        $product->image = $image;
        $product->save();

        $this->actingAs($user)->get("/images/{$image}/thumb")->assertOk()->assertStreamedContent('full');
    }

    public function test_large_files_and_foreign_products_are_rejected(): void
    {
        [$owner, $other] = User::factory()->count(2)->create();
        $id = (string) Str::uuid();
        $this->upload($owner, $id)->assertOk();

        $this->upload($other, $id)->assertForbidden();
        $this->upload($owner, $id, file: UploadedFile::fake()->create('big.jpg', 800, 'image/jpeg'))->assertUnprocessable();
    }
}
