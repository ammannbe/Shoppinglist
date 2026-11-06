<?php

namespace App\Http\Controllers;

use App\Models\PlannerState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Week planner state across devices (resources/js/planner.js): one JSON document per user,
 * the client keeps a copy in localStorage. Last write wins by the client's timestamp.
 */
class PlannerStateController extends Controller
{
    /** Maximum size of the state as JSON (bytes). */
    public const MAX_BYTES = 1_000_000;

    public function show(Request $request): JsonResponse
    {
        return response()->json(self::payload(PlannerState::find($request->user()->id)));
    }

    /**
     * Stores the state if it is newer than the stored one. Returns the current state either way
     * (`accepted` false: the server's state is newer and wins).
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => ['present', 'array'],
            'updated_at' => ['required', 'integer', 'min:1'],
        ]);
        if (strlen(json_encode($data['state'])) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['state' => __('The planner state is too large.')]);
        }
        $userId = $request->user()->id;
        // A device clock running ahead must not win against all later changes.
        $updatedAt = min((int) $data['updated_at'], now()->getTimestampMs());

        $accepted = DB::transaction(function () use ($userId, $data, $updatedAt) {
            $current = PlannerState::lockForUpdate()->find($userId);
            if ($current && $current->updated_at >= $updatedAt) {
                return false;
            }
            PlannerState::updateOrCreate(['user_id' => $userId], ['state' => $data['state'], 'updated_at' => $updatedAt]);

            return true;
        });

        return response()->json([...self::payload(PlannerState::find($userId)), 'accepted' => $accepted]);
    }

    private static function payload(?PlannerState $state): array
    {
        return ['state' => $state?->state, 'updated_at' => $state?->updated_at ?? 0];
    }
}
