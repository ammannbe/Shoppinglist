<?php

namespace App\Jobs;

use App\Households\KontoClient;
use App\Households\KontoHouseholds;
use App\Households\KontoUnavailable;
use App\Models\Household;
use App\Models\ShoppingList;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells the AccountManager which shopping lists are shared with a mirrored household (PUT shares, only for
 * the overview there). Only changes are sent (hash of the last report); failures are harmless,
 * households:sync reports again.
 */
class ReportKontoShares implements ShouldQueue
{
    use Queueable;

    public const RESOURCE_TYPE = 'shopping_list';

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Household $household) {}

    /**
     * @param  Household|iterable<Household>  $households
     */
    public static function dispatchFor(Household|iterable $households): void
    {
        if (! KontoHouseholds::enabled() || ! config('households.konto.report_shares')) {
            return;
        }

        foreach ($households instanceof Household ? [$households] : $households as $household) {
            if ($household->konto_household_uuid !== null) {
                static::dispatch($household)->afterCommit();
            }
        }
    }

    /**
     * @return list<array{resource_type: string, resource_id: string, label: string}>
     */
    public static function shares(Household $household): array
    {
        return $household->shoppingLists()->orderBy('shopping_lists.created_at')->orderBy('shopping_lists.id')->get()
            ->map(fn (ShoppingList $list) => [
                'resource_type' => self::RESOURCE_TYPE,
                'resource_id' => (string) $list->id,
                'label' => mb_substr(trim($list->name), 0, 255),
            ])->take(500)->values()->all();
    }

    public function handle(KontoClient $client): void
    {
        $household = $this->household->fresh();
        if ($household?->konto_household_uuid === null || ! KontoHouseholds::enabled()) {
            return;
        }

        $shares = self::shares($household);
        $hash = hash('sha256', json_encode($shares, JSON_THROW_ON_ERROR));
        if ($hash === $household->konto_shares_hash) {
            return;
        }

        try {
            if ($client->putShares($household->konto_household_uuid, $shares)) {
                $household->forceFill(['konto_shares_hash' => $hash])->saveQuietly();
            }
        } catch (KontoUnavailable $exception) {
            KontoHouseholds::logUnavailable($exception, 'reporting the shares');
            if ($this->attempts() < $this->tries) {
                $this->release(300);
            }
        }
    }
}
