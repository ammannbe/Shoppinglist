<?php

namespace App\Recipes;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The recipe app is not connected, unreachable or refused the request.
 */
class RecipesException extends RuntimeException
{
    /**
     * Why the recipe app refused a request (its `message` and validation `errors`), for the log.
     *
     * @var array<string, mixed>
     */
    private array $details = [];

    public static function fromResponse(Response $response): self
    {
        $exception = new self('Request failed with status '.$response->status().'.', $response->status());
        $body = $response->json();
        $exception->details = is_array($body)
            ? array_filter(['message' => $body['message'] ?? null, 'errors' => $body['errors'] ?? null], fn (mixed $value): bool => $value !== null)
            : ['body' => Str::limit(trim($response->body()), 300)];

        return $exception;
    }

    /**
     * Context Laravel adds to the log entry when the exception is reported.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->details === [] ? [] : ['recipes_response' => $this->details];
    }
}
