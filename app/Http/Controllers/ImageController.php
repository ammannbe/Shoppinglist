<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Product images. The client scales them down before the upload (resources/js/image.js),
 * the server only accepts small files. The image id is copied to the items, so members
 * of shared lists may see the images of the items on their lists.
 */
class ImageController extends Controller
{
    private const DIRECTORY = 'images';

    /**
     * Upload (or replace) the image of an own product. Unknown products are created,
     * they may not be synced yet.
     */
    public function store(Request $request, string $productId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'image' => ['required', 'file', 'mimetypes:image/webp,image/jpeg,image/png', 'max:400', 'dimensions:max_width=1600,max_height=1600'],
        ]);

        $product = $this->ownProduct($request, $productId, $validated['name']);
        $old = $product->image;

        $image = (string) Str::uuid();
        Storage::putFileAs(self::DIRECTORY, $request->file('image'), $image);

        $this->saveImage($product, $image);
        $this->deleteUnused($old);

        return response()->json(['image' => $image, 'version' => $product->version]);
    }

    public function destroy(Request $request, string $productId): JsonResponse
    {
        $product = Product::whereKey($productId)->where('owner_id', $request->user()->id)->firstOrFail();
        $old = $product->image;

        $this->saveImage($product, null);
        $this->deleteUnused($old);

        return response()->json(['image' => null, 'version' => $product->version]);
    }

    /**
     * Images of own products and of items on accessible lists.
     */
    public function show(Request $request, string $image): Response
    {
        abort_unless(Str::isUuid($image), 404);
        $user = $request->user();

        $allowed = Product::where('image', $image)->where('owner_id', $user->id)->exists()
            || Item::where('image', $image)->whereIn('shopping_list_id', $user->accessibleListIds())->exists();
        $path = self::DIRECTORY.'/'.$image;
        abort_unless($allowed && Storage::exists($path), 404);

        // A new image gets a new id, so the file never changes.
        return Storage::response($path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    private function ownProduct(Request $request, string $productId, string $name): Product
    {
        abort_unless(Str::isUuid($productId), 404);
        $product = Product::withTrashed()->find($productId);

        if ($product === null) {
            $product = new Product(['name' => $name]);
            $product->id = $productId;
            $product->owner()->associate($request->user());
        }

        abort_if($product->owner_id !== $request->user()->id || $product->trashed(), 403);

        return $product;
    }

    /**
     * New version and timestamp, so the clients pull the product again.
     */
    private function saveImage(Product $product, ?string $image): void
    {
        $product->image = $image;
        $product->version = $product->exists ? $product->version + 1 : 1;
        $product->save();
    }

    private function deleteUnused(?string $image): void
    {
        if ($image === null || Product::withTrashed()->where('image', $image)->exists() || Item::withTrashed()->where('image', $image)->exists()) {
            return;
        }
        Storage::delete(self::DIRECTORY.'/'.$image);
    }
}
