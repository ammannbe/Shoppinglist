<?php

namespace App\Models;

use App\Support\ShopRegions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['store', 'region', 'name', 'brand', 'price', 'regular_price', 'discount', 'valid_from', 'valid_until', 'shared'])]
class Offer extends Model
{
    use HasUuids;

    /** Offers without an end date are removed this many days after they were added. */
    public const UNDATED_DAYS = 14;

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'regular_price' => 'float',
            'valid_from' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'shared' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Own and shared offers that have not expired; shared ones of other regions than the user's
     * (preferences.regions) are left out.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $hidden = ShopRegions::hidden((array) ($user->preferences['regions'] ?? []));

        return $query->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere(fn (Builder $q) => $q->where('shared', true)
            ->when($hidden, fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('region')->orWhereNotIn('region', $hidden)))))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->where(fn (Builder $q) => $q->whereNotNull('valid_until')->orWhere('created_at', '>=', now()->subDays(self::UNDATED_DAYS)));
    }

    /**
     * A date as YYYY-MM-DD, null if it is none (the AI may return anything).
     */
    public static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toClient(User $user): array
    {
        return [
            'id' => $this->id,
            'store' => $this->store,
            'region' => $this->region,
            'name' => $this->name,
            'brand' => $this->brand,
            'price' => $this->price,
            'regular_price' => $this->regular_price,
            'discount' => $this->discount,
            'valid_from' => $this->valid_from?->format('Y-m-d'),
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'shared' => $this->shared,
            'own' => $this->user_id === $user->id,
            // Note of a shopping list entry for this offer (added from the offers or by the planner).
            'note' => self::note([...$this->only(['name', 'brand', 'price', 'regular_price', 'discount']), 'valid_until' => $this->valid_until?->format('Y-m-d')], $this->name),
        ];
    }

    /**
     * Note of a shopping list entry bought on offer: "Aktion −30 %, Bio-Kartoffeln, CHF 3.95 (statt 5.60), bis 14.11.".
     * The entry may have a generic name ("Kartoffeln"), the note then names the product on offer;
     * the product is left out when it is the entry's name.
     *
     * @param  array<string, mixed>  $offer  name, brand, price, regular_price, discount, valid_until (Y-m-d)
     */
    public static function note(array $offer, string $name): string
    {
        $chf = fn ($amount) => number_format((float) $amount, 2, '.', "'");
        $product = trim(($offer['brand'] ?? '').' '.($offer['name'] ?? ''));
        $until = filled($offer['valid_until'] ?? null) ? Carbon::make($offer['valid_until']) : null;
        $parts = array_filter([
            __('Offer').(filled($offer['discount'] ?? null) ? ' '.$offer['discount'] : ''),
            mb_strtolower($product) !== mb_strtolower(trim($name)) ? $product : null,
            isset($offer['price']) ? 'CHF '.$chf($offer['price'])
                .(isset($offer['regular_price']) ? ' ('.__('instead of :price', ['price' => $chf($offer['regular_price'])]).')' : '') : null,
            $until ? __('until :date', ['date' => $until->format('j.n.')]) : null,
        ]);

        return mb_substr(implode(', ', $parts), 0, 255);
    }
}
