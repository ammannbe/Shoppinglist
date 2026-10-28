<?php

namespace App\Http\Controllers;

use App\Sync\ItemResource;
use App\Sync\ListResource;
use App\Sync\ProductResource;
use App\Sync\RetryLaterException;
use App\Sync\SyncResource;
use App\Sync\UnitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    /**
     * @var array<string, class-string<SyncResource>>
     */
    private const RESOURCES = [
        'lists' => ListResource::class,
        'items' => ItemResource::class,
        'products' => ProductResource::class,
        'units' => UnitResource::class,
    ];

    public function pull(Request $request, string $collection): JsonResponse
    {
        $validated = $request->validate([
            'updated_at' => ['nullable', 'date_format:Y-m-d H:i:s.v'],
            'id' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return response()->json($this->resource($collection)->pull(
            $request->user(),
            $validated,
            (int) ($validated['limit'] ?? 100),
        ));
    }

    public function push(Request $request, string $collection): JsonResponse
    {
        $rows = $request->validate([
            '*' => ['array'],
            '*.assumedMasterState' => ['nullable', 'array'],
            '*.newDocumentState' => ['required', 'array'],
            '*.newDocumentState.id' => ['required', 'uuid'],
            '*.newDocumentState._deleted' => ['boolean'],
            '*.newDocumentState.version' => ['nullable', 'integer', 'min:1'],
            '*.newDocumentState.name' => ['required_unless:*.newDocumentState._deleted,true', 'nullable', 'string', 'max:255'],
            '*.newDocumentState.shopping_list_id' => ['nullable', 'uuid'],
            '*.newDocumentState.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            '*.newDocumentState.unit' => ['nullable', 'string', 'max:255'],
            '*.newDocumentState.done' => ['nullable', 'boolean'],
        ]);

        abort_if(count($rows) > 500, 413);

        try {
            return response()->json($this->resource($collection)->push($request->user(), array_values($rows)));
        } catch (RetryLaterException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    private function resource(string $collection): SyncResource
    {
        abort_unless(isset(self::RESOURCES[$collection]), 404);

        return app(self::RESOURCES[$collection]);
    }
}
