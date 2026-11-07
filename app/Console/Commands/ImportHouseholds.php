<?php

namespace App\Console\Commands;

use App\Households\KontoClient;
use App\Households\KontoHouseholds;
use App\Households\KontoUnavailable;
use App\Models\Household;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Switching from HOUSEHOLDS_DRIVER=local to konto: proposes the local households to the
 * AccountManager (members with oidc_sub only), where an admin creates or merges them, then maps the
 * confirmed ones (konto_household_uuid, name and members from the AccountManager, shares stay).
 * Proposing again is allowed (idempotent by local id). --check only polls the state (hourly).
 */
#[Signature('households:import {--check : Only fetch the state of the proposals}')]
#[Description('Propose the local households to the AccountManager and map the confirmed ones')]
class ImportHouseholds extends Command
{
    public function handle(KontoClient $client, KontoHouseholds $households): int
    {
        if (! KontoHouseholds::enabled()) {
            $this->error('Set HOUSEHOLDS_DRIVER=konto, KONTO_URL, KONTO_SERVICE_KEY and KONTO_SECRET first.');

            return self::FAILURE;
        }

        $check = (bool) $this->option('check');
        if ($check && ! Household::whereNull('konto_household_uuid')->where('konto_import_status', 'pending')->exists()) {
            return self::SUCCESS;
        }

        try {
            if (! $check) {
                $this->propose($client);
            }
            $this->apply($client->importStatus(), $households);
        } catch (KontoUnavailable $exception) {
            KontoHouseholds::logUnavailable($exception, 'households:import');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function propose(KontoClient $client): void
    {
        $local = Household::whereNull('konto_household_uuid')
            ->with(['members' => fn ($query) => $query->orderByPivot('joined_at')->orderBy('users.id')])
            ->orderBy('id')
            ->get();

        $proposals = $local->map(fn (Household $household) => [
            'local_id' => (string) $household->id,
            'name' => mb_substr($household->name, 0, 100),
            'members' => $household->members->whereNotNull('oidc_sub')->take(100)
                ->map(fn (User $user) => ['sub' => $user->oidc_sub, 'role' => $user->pivot->role])->values()->all(),
        ])->filter(fn (array $proposal) => $proposal['members'] !== [])->values();

        if ($local->count() > $proposals->count()) {
            $this->warn(($local->count() - $proposals->count()).' households have no member with a linked login (oidc_sub) and stay local.');
        }

        foreach ($proposals->chunk(500) as $chunk) {
            foreach ($client->propose($chunk->values()->all()) as $state) {
                Household::whereKey((int) $state['local_id'])->whereNull('konto_household_uuid')
                    ->update(['konto_import_status' => $state['status'] === 'rejected' ? 'rejected' : 'pending']);
            }
        }
        $this->info("Proposed {$proposals->count()} households to the AccountManager.");
    }

    /**
     * @param  list<array{local_id: string, status: string, household_uuid: string|null, household: array<string, mixed>|null}>  $states
     */
    private function apply(array $states, KontoHouseholds $households): void
    {
        $counts = ['confirmed' => 0, 'pending' => 0, 'rejected' => 0];

        foreach ($states as $state) {
            $local = ctype_digit($state['local_id']) ? Household::whereKey((int) $state['local_id'])->whereNull('konto_household_uuid')->first() : null;
            if ($local === null) {
                continue;
            }

            if ($state['status'] === 'confirmed' && $state['household'] !== null) {
                $households->adopt($local, $state['household']);
                $counts['confirmed']++;
            } elseif (in_array($state['status'], ['pending', 'rejected'], true)) {
                $local->forceFill(['konto_import_status' => $state['status']])->save();
                $counts[$state['status']]++;
            }
        }

        $this->info("Confirmed: {$counts['confirmed']}, pending: {$counts['pending']}, rejected: {$counts['rejected']}.");
    }
}
