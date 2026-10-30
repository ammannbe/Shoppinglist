<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['store', 'name', 'brand', 'price', 'regular_price', 'discount', 'valid_from', 'valid_until', 'shared'])]
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
     * Own and shared offers that have not expired.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere('shared', true))
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
            'name' => $this->name,
            'brand' => $this->brand,
            'price' => $this->price,
            'regular_price' => $this->regular_price,
            'discount' => $this->discount,
            'valid_from' => $this->valid_from?->format('Y-m-d'),
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'shared' => $this->shared,
            'own' => $this->user_id === $user->id,
        ];
    }
}
