<?php

namespace App\Households;

use App\Entitlements\Entitlements;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * API of the AccountManager (docs/HOUSEHOLDS-API.md there, section 3, and docs/ENTITLEMENTS-API.md, section 4):
 * HTTP Basic with the service key and the service secret. Every failure is a AccountManagerUnavailable, 404 is an answer.
 *
 * @phpstan-type EntitlementSnapshot array{sub: string, status: string, admin: bool, features: list<string>, version: int}
 */
class AccountManagerClient
{
    /**
     * Total timeout of a request in seconds (the OIDC login uses a shorter one).
     */
    private int $timeout = 10;

    public static function configured(): bool
    {
        return filled(config('households.account_manager.url'))
            && filled(config('households.account_manager.key'))
            && filled(config('households.account_manager.secret'));
    }

    public function withTimeout(int $seconds): static
    {
        $client = clone $this;
        $client->timeout = $seconds;

        return $client;
    }

    /**
     * Entitlements of a user for this service (ENTITLEMENTS-API.md 4.1), null if the user is unknown
     * there or has no access (404).
     *
     * @return EntitlementSnapshot|null
     */
    public function entitlements(string $sub): ?array
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->get('entitlements/'.rawurlencode($sub)), allowNotFound: true);

        if ($response->notFound()) {
            return null;
        }

        try {
            return self::validEntitlements($response->json('data'));
        } catch (ValidationException) {
            throw new AccountManagerUnavailable('The AccountManager returned invalid entitlements.');
        }
    }

    /**
     * Validates an entitlements snapshot from a webhook or an API answer. It must be meant for this
     * service; an unknown status counts as "none", features and admin only count while active.
     *
     * @return EntitlementSnapshot
     *
     * @throws ValidationException
     */
    public static function validEntitlements(mixed $snapshot): array
    {
        /** @var array{sub: string, status: string, admin: bool, features: list<string>, version: int|string} $data */
        $data = Validator::make(['entitlements' => $snapshot], [
            'entitlements' => ['required', 'array'],
            'entitlements.sub' => ['required', 'string', 'max:255'],
            'entitlements.service' => ['sometimes', 'string', Rule::in([(string) config('households.account_manager.key')])],
            'entitlements.status' => ['required', 'string', 'max:20'],
            'entitlements.admin' => ['required', 'boolean'],
            'entitlements.features' => ['present', 'array'],
            'entitlements.features.*' => ['string', 'max:255'],
            'entitlements.version' => ['required', 'integer', 'min:0'],
        ])->validate()['entitlements'];

        $status = in_array($data['status'], Entitlements::STATUSES, true) ? $data['status'] : Entitlements::NONE;
        $active = $status === Entitlements::ACTIVE;
        $features = $active ? array_values(array_unique(array_map('strval', $data['features']))) : [];
        sort($features);

        return [
            'sub' => (string) $data['sub'],
            'status' => $status,
            'admin' => $active && (bool) $data['admin'],
            'features' => $features,
            'version' => (int) $data['version'],
        ];
    }

    /**
     * All households of a user (3.2). Complete: missing mirrored households were left or deleted.
     *
     * @return list<array{uuid: string, name: string, version: int, members: list<array{sub: string, role: string}>}>
     */
    public function households(string $sub): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('households', ['sub' => $sub]));

        return array_map(fn ($snapshot) => $this->snapshot($snapshot), $this->list($response->json('data')));
    }

    /**
     * One household (3.3), null if it is gone or no longer concerns a user of this app (404).
     *
     * @return array{uuid: string, name: string, version: int, members: list<array{sub: string, role: string}>}|null
     */
    public function household(string $uuid): ?array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('households/'.rawurlencode($uuid)), allowNotFound: true);

        return $response->notFound() ? null : $this->snapshot($response->json('data'));
    }

    /**
     * Replaces what this app shares with the household (3.4). False if the household is unknown there.
     *
     * @param  list<array{resource_type: string, resource_id: string, label: string}>  $shares
     */
    public function putShares(string $uuid, array $shares): bool
    {
        $response = $this->send(fn (PendingRequest $http) => $http->put('households/'.rawurlencode($uuid).'/shares', ['shares' => $shares]), allowNotFound: true);

        return ! $response->notFound();
    }

    /**
     * Proposes local households for the switch (3.5), idempotent by local_id.
     *
     * @param  list<array{local_id: string, name: string, members: list<array{sub: string, role: string}>}>  $households
     * @return list<array{local_id: string, status: string, household_uuid: string|null, household: array<string, mixed>|null}>
     */
    public function propose(array $households): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('households/import', ['households' => $households]));

        return $this->importStates($response);
    }

    /**
     * State of all proposals of this app (3.5).
     *
     * @return list<array{local_id: string, status: string, household_uuid: string|null, household: array<string, mixed>|null}>
     */
    public function importStatus(): array
    {
        return $this->importStates($this->send(fn (PendingRequest $http) => $http->get('households/import')));
    }

    /**
     * Validates a snapshot from a webhook or an API answer.
     *
     * @return array{uuid: string, name: string, version: int, members: list<array{sub: string, role: string}>, deleted?: bool}
     *
     * @throws ValidationException
     */
    public static function validSnapshot(mixed $snapshot): array
    {
        $data = Validator::make(['household' => $snapshot], [
            'household' => ['required', 'array'],
            'household.uuid' => ['required', 'uuid'],
            'household.name' => ['required', 'string', 'max:255'],
            'household.version' => ['required', 'integer', 'min:0'],
            'household.members' => ['present', 'array'],
            'household.members.*.sub' => ['required', 'string', 'max:255'],
            'household.members.*.role' => ['nullable', 'string'],
            'household.deleted' => ['sometimes', 'boolean'],
        ])->validate()['household'];

        return [
            'uuid' => strtolower($data['uuid']),
            'name' => trim($data['name']),
            'version' => (int) $data['version'],
            'members' => array_values(array_map(fn (array $member) => [
                'sub' => (string) $member['sub'],
                'role' => ($member['role'] ?? null) === 'admin' ? 'admin' : 'member',
            ], $data['members'])),
            ...(isset($data['deleted']) ? ['deleted' => (bool) $data['deleted']] : []),
        ];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('households.account_manager.url'), '/').'/api')
            ->withBasicAuth((string) config('households.account_manager.key'), (string) config('households.account_manager.secret'))
            ->acceptJson()
            ->connectTimeout(min(3, $this->timeout))
            ->timeout($this->timeout)
            ->withoutRedirecting();
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    private function send(callable $request, bool $allowNotFound = false): Response
    {
        if (! self::configured()) {
            throw new AccountManagerUnavailable('The AccountManager is not configured (ACCOUNT_MANAGER_URL, ACCOUNT_MANAGER_SERVICE_KEY, ACCOUNT_MANAGER_SECRET).');
        }

        try {
            $response = $request($this->http());
        } catch (ConnectionException $exception) {
            throw new AccountManagerUnavailable('The AccountManager is not reachable: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->successful() || ($allowNotFound && $response->notFound())) {
            return $response;
        }

        throw new AccountManagerUnavailable("The AccountManager answered with HTTP {$response->status()}.");
    }

    /**
     * @return array{uuid: string, name: string, version: int, members: list<array{sub: string, role: string}>}
     */
    private function snapshot(mixed $data): array
    {
        try {
            $snapshot = self::validSnapshot($data);
        } catch (ValidationException) {
            throw new AccountManagerUnavailable('The AccountManager returned an invalid household.');
        }
        unset($snapshot['deleted']);

        return $snapshot;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $data): array
    {
        if (! is_array($data) || ! array_is_list($data)) {
            throw new AccountManagerUnavailable('The AccountManager returned an unexpected answer.');
        }

        return $data;
    }

    /**
     * @return list<array{local_id: string, status: string, household_uuid: string|null, household: array<string, mixed>|null}>
     */
    private function importStates(Response $response): array
    {
        return array_values(array_filter(array_map(function ($state) {
            if (! is_array($state) || ! isset($state['local_id'], $state['status'])) {
                return null;
            }
            $confirmed = $state['status'] === 'confirmed' && is_array($state['household'] ?? null);

            return [
                'local_id' => (string) $state['local_id'],
                'status' => (string) $state['status'],
                'household_uuid' => $confirmed ? (string) $state['household_uuid'] : null,
                'household' => $confirmed ? $this->snapshot($state['household']) : null,
            ];
        }, $this->list($response->json('data')))));
    }
}
