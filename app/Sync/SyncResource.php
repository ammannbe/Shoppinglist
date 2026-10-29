<?php

namespace App\Sync;

use App\Models\Concerns\Syncable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Server side of the RxDB HTTP replication protocol for one collection.
 *
 * Pull: documents changed after a checkpoint (updated_at + id).
 * Push: rows of {assumedMasterState, newDocumentState}; returns the current
 * server documents for every row that conflicts (version mismatch).
 *
 * @see https://rxdb.info/replication-http.html
 */
abstract class SyncResource
{
    /**
     * Query of all documents visible to the user, including deleted ones.
     */
    abstract protected function pullQuery(User $user): Builder;

    /**
     * Column used as checkpoint tie-breaker (and document id).
     */
    protected function checkpointIdColumn(): string
    {
        return 'id';
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function document(Model $model, User $user): array;

    /**
     * Apply one pushed row. Return the server document on conflict, null otherwise.
     *
     * @param  array<string, mixed>|null  $assumed
     * @param  array<string, mixed>  $new
     * @return array<string, mixed>|null
     *
     * @throws RetryLaterException
     */
    abstract protected function pushRow(User $user, ?array $assumed, array $new): ?array;

    /**
     * @param  array{updated_at?: string|null, id?: string|null}  $checkpoint
     * @return array{documents: list<array<string, mixed>>, checkpoint: array{updated_at: string|null, id: string|null}}
     */
    public function pull(User $user, array $checkpoint, int $limit): array
    {
        $models = $this->pullQuery($user)
            ->afterCheckpoint($checkpoint, $this->checkpointIdColumn())
            ->limit($limit)
            ->get();

        $last = $models->last();

        return [
            'documents' => $models->map(fn (Model $model) => $this->document($model, $user))->values()->all(),
            'checkpoint' => $last
                ? ['updated_at' => $last->syncTimestamp(), 'id' => (string) $last->{$this->checkpointIdColumn()}]
                : ['updated_at' => $checkpoint['updated_at'] ?? null, 'id' => $checkpoint['id'] ?? null],
        ];
    }

    /**
     * @param  list<array{assumedMasterState?: array<string, mixed>|null, newDocumentState: array<string, mixed>}>  $rows
     * @return list<array<string, mixed>>
     */
    public function push(User $user, array $rows): array
    {
        $conflicts = [];

        foreach ($rows as $row) {
            $conflict = DB::transaction(fn () => $this->pushRow(
                $user,
                $row['assumedMasterState'] ?? null,
                $row['newDocumentState'],
            ));

            if ($conflict !== null) {
                $conflicts[] = $conflict;
            }
        }

        return $conflicts;
    }

    /**
     * A row conflicts when the client did not know the current server version.
     *
     * @param  Model&object{version: int}  $model
     * @param  array<string, mixed>|null  $assumed
     */
    protected function isConflict(Model $model, ?array $assumed): bool
    {
        return $assumed === null || (int) ($assumed['version'] ?? 0) !== (int) $model->version;
    }

    /**
     * Clients increase the version on every local change and RxDB remembers
     * the pushed state as master, so the server keeps the client's version
     * (several offline edits can be pushed at once).
     *
     * @param  Model&object{version: int}  $model
     * @param  array<string, mixed>  $new
     */
    protected function nextVersion(Model $model, array $new): int
    {
        return max((int) ($new['version'] ?? 0), $model->version + 1);
    }

    /**
     * @param  class-string<Model>  $class
     */
    protected function findForUpdate(string $class, string $id): ?Model
    {
        /** @var Builder $query */
        $query = $class::withTrashed();

        return $query->lockForUpdate()->find($id);
    }

    protected function deleted(Model $model): bool
    {
        /** @var Model&Syncable $model */
        return $model->trashed();
    }
}
