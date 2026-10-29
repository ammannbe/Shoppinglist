<?php

namespace App\Recipes;

use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * API of the connected recipe app (OAuth 2 with Laravel Passport on the other side).
 * Tokens are stored per user (users.recipes_connection) and refreshed when expired.
 */
class RecipesClient
{
    public static function enabled(): bool
    {
        return filled(config('services.recipes.url')) && filled(config('services.recipes.client_id'));
    }

    public static function redirectUri(): string
    {
        return route('recipes.callback');
    }

    public function authorizeUrl(string $state): string
    {
        return config('services.recipes.url').'/oauth/authorize?'.http_build_query([
            'client_id' => config('services.recipes.client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => '',
            'state' => $state,
        ]);
    }

    /**
     * Exchange the authorization code and store the connection.
     *
     * @throws RequestException
     */
    public function connect(User $user, string $code): void
    {
        $tokens = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri()]);
        $this->store($user, $tokens);

        $account = $this->get($user, '/api/user');
        $user->recipes_connection = [...$user->recipes_connection, 'account' => [
            'name' => $account['name'] ?? null,
            'email' => $account['email'] ?? null,
        ]];
        $user->save();
    }

    public function disconnect(User $user): void
    {
        $user->forceFill(['recipes_connection' => null])->save();
    }

    public static function connected(User $user): bool
    {
        return self::enabled() && filled($user->recipes_connection['access_token'] ?? null);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RecipesException
     */
    public function get(User $user, string $path, array $query = []): array
    {
        return $this->request($user, fn (string $token) => Http::withToken($token)->acceptJson()->timeout(20)->get($this->url($path), $query));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws RecipesException
     */
    public function post(User $user, string $path, array $data): array
    {
        return $this->request($user, fn (string $token) => Http::withToken($token)->acceptJson()->timeout(20)->post($this->url($path), $data));
    }

    /**
     * @param  callable(string): Response  $send
     * @return array<string, mixed>
     */
    private function request(User $user, callable $send): array
    {
        if (! self::connected($user)) {
            throw new RecipesException('Not connected.');
        }

        if (($user->recipes_connection['expires_at'] ?? PHP_INT_MAX) < now()->addMinute()->timestamp) {
            $this->refresh($user);
        }

        $response = $send($user->recipes_connection['access_token']);
        if ($response->status() === 401) {
            $this->refresh($user);
            $response = $send($user->recipes_connection['access_token']);
        }

        if ($response->failed()) {
            throw new RecipesException('Request failed with status '.$response->status().'.', $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * A refused refresh token ends the connection (revoked in the recipe app).
     */
    private function refresh(User $user): void
    {
        try {
            $this->store($user, $this->token([
                'grant_type' => 'refresh_token',
                'refresh_token' => $user->recipes_connection['refresh_token'] ?? '',
            ]));
        } catch (RequestException $e) {
            if ($e->response->status() >= 400 && $e->response->status() < 500) {
                $this->disconnect($user);
            }
            throw new RecipesException('The connection expired.', 401, $e);
        }
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    private function token(array $params): array
    {
        return Http::asForm()->acceptJson()->timeout(20)->post(config('services.recipes.url').'/oauth/token', [
            ...$params,
            'client_id' => config('services.recipes.client_id'),
            'client_secret' => config('services.recipes.client_secret'),
        ])->throw()->json();
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    private function store(User $user, array $tokens): void
    {
        $user->recipes_connection = [
            ...($user->recipes_connection ?? []),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? ($user->recipes_connection['refresh_token'] ?? null),
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600))->timestamp,
        ];
        $user->save();
    }

    private function url(string $path): string
    {
        return config('services.recipes.url').$path;
    }
}
