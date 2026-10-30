<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Offers pool (#/offers): own offers and the ones other users shared. Reading offers with the
 * AI saves them here too (PlannerController::offers). Needs a connection, no offline sync.
 */
class OfferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['offers' => Offer::visibleTo($user)
            ->orderBy('store')->orderBy('name')
            ->get()
            ->map(fn (Offer $offer) => $offer->toClient($user))]);
    }

    public function store(Request $request): JsonResponse
    {
        $offer = self::save($request->user(), $this->validated($request));

        return response()->json(['offer' => $offer->toClient($request->user())], 201);
    }

    public function update(Request $request, Offer $offer): JsonResponse
    {
        abort_unless($offer->user_id === $request->user()->id, 403);
        $data = $this->validated($request, partial: true);
        // The rule above only compares dates sent together.
        $from = array_key_exists('valid_from', $data) ? $data['valid_from'] : $offer->valid_from?->format('Y-m-d');
        $until = array_key_exists('valid_until', $data) ? $data['valid_until'] : $offer->valid_until?->format('Y-m-d');
        if ($from !== null && $until !== null && $from > $until) {
            throw ValidationException::withMessages(['valid_until' => __('validation.after_or_equal', ['attribute' => self::attributeNames()['valid_until'], 'date' => self::attributeNames()['valid_from']])]);
        }
        $offer->update($data);

        return response()->json(['offer' => $offer->toClient($request->user())]);
    }

    public function destroy(Request $request, Offer $offer): Response
    {
        abort_unless($offer->user_id === $request->user()->id, 403);
        $offer->delete();

        return response()->noContent();
    }

    /**
     * Saves an offer; the same one already visible to the user (same shop, name, brand and end
     * date) is updated instead of added twice, if it is the user's own, or just returned.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(User $user, array $data): Offer
    {
        $data['valid_from'] = Offer::date($data['valid_from'] ?? null);
        $data['valid_until'] = Offer::date($data['valid_until'] ?? null);

        $same = Offer::visibleTo($user)
            ->where('store', $data['store'] ?? null)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($data['name']))])
            ->where('brand', $data['brand'] ?? null)
            ->where(fn ($q) => isset($data['valid_until']) ? $q->whereDate('valid_until', $data['valid_until']) : $q->whereNull('valid_until'))
            ->orderByRaw('user_id = ? desc', [$user->id])
            ->first();

        if ($same !== null) {
            if ($same->user_id === $user->id) {
                $same->update($data);
            }

            return $same;
        }

        $offer = new Offer($data);
        $offer->user()->associate($user);
        $offer->save();

        return $offer;
    }

    /**
     * @return array<string, string>
     */
    private static function attributeNames(): array
    {
        return ['valid_from' => __('Valid from'), 'valid_until' => __('Valid until'), 'regular_price' => __('Instead of'), 'name' => __('Product')];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'store' => ['nullable', 'string', 'max:255'],
            'name' => [$required, 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'regular_price' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'discount' => ['nullable', 'string', 'max:100'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'shared' => [$partial ? 'sometimes' : 'nullable', 'boolean'],
        ], [], self::attributeNames());
    }
}
