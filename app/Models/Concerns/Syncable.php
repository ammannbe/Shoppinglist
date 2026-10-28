<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Shared behaviour of all models replicated to the client database.
 */
trait Syncable
{
    use HasUuids, SoftDeletes;

    public const SYNC_DATE_FORMAT = 'Y-m-d H:i:s.v';

    public function initializeSyncable(): void
    {
        $this->dateFormat = self::SYNC_DATE_FORMAT;
    }

    /**
     * Only rows changed after the given checkpoint, ordered for stable paging.
     *
     * @param  array{updated_at?: string|null, id?: string|null}  $checkpoint
     */
    public function scopeAfterCheckpoint(Builder $query, array $checkpoint, string $idColumn = 'id'): Builder
    {
        $table = $this->getTable();

        if (! empty($checkpoint['updated_at'])) {
            $query->where(function (Builder $q) use ($table, $checkpoint, $idColumn) {
                $q->where("$table.updated_at", '>', $checkpoint['updated_at'])
                    ->orWhere(function (Builder $q) use ($table, $checkpoint, $idColumn) {
                        $q->where("$table.updated_at", $checkpoint['updated_at'])
                            ->where("$table.$idColumn", '>', (string) ($checkpoint['id'] ?? ''));
                    });
            });
        }

        return $query->orderBy("$table.updated_at")->orderBy("$table.$idColumn");
    }

    public function syncTimestamp(): string
    {
        return $this->updated_at->format(self::SYNC_DATE_FORMAT);
    }
}
