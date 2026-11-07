<?php

namespace App\Households;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Households API of the AccountManager (docs/HOUSEHOLDS-API.md there, section 3): HTTP Basic with
 * the service key and the service secret. Every failure is a KontoUnavailable, 404 is an answer.
 */
class KontoClient
{
    public static function configured(): bool
    {
        return filled(config('households.konto.url'))
            && filled(config('households.konto.key'))
            && filled(config('households.konto.secret'));
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
        return Http::baseUrl(rtrim((string) config('households.konto.url'), '/').'/api')
            ->withBasicAuth((string) config('households.konto.key'), (string) config('households.konto.secret'))
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->withoutRedirecting();
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    private function send(callable $request, bool $allowNotFound = false): Response
    {
        if (! self::configured()) {
            throw new KontoUnavailable('The AccountManager is not configured (KONTO_URL, KONTO_SERVICE_KEY, KONTO_SECRET).');
        }

        try {
            $response = $request($this->http());
        } catch (ConnectionException $exception) {
            throw new KontoUnavailable('The AccountManager is not reachable: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->successful() || ($allowNotFound && $response->notFound())) {
            return $response;
        }

        throw new KontoUnavailable("The AccountManager answered with HTTP {$response->status()}.");
    }

    /**
     * @return array{uuid: string, name: string, version: int, members: list<array{sub: string, role: string}>}
     */
    private function snapshot(mixed $data): array
    {
        try {
            $snapshot = self::validSnapshot($data);
        } catch (ValidationException) {
            throw new KontoUnavailable('The AccountManager returned an invalid household.');
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
            throw new KontoUnavailable('The AccountManager returned an unexpected answer.');
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
