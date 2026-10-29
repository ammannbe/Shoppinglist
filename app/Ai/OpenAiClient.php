<?php

namespace App\Ai;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal client for the OpenAI Responses API with structured (JSON schema) output.
 * No SDK: one endpoint is enough, and Http::fake() keeps the tests simple.
 */
class OpenAiClient
{
    public static function enabled(): bool
    {
        return filled(config('services.openai.key'));
    }

    /**
     * @param  list<array<string, mixed>>  $content  input parts, see text(), file()
     * @param  array<string, mixed>  $schema  JSON schema of the answer (strict)
     * @return array<string, mixed>
     *
     * @throws AiException
     */
    public function structured(string $instructions, array $content, string $name, array $schema): array
    {
        if (! self::enabled()) {
            throw new AiException('The AI assistant is not configured.');
        }

        $payload = [
            'model' => config('services.openai.model'),
            'instructions' => $instructions,
            'input' => [['role' => 'user', 'content' => $content]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => $name, 'schema' => $schema, 'strict' => true]],
            'store' => false,
        ];
        if (filled(config('services.openai.reasoning_effort'))) {
            $payload['reasoning'] = ['effort' => config('services.openai.reasoning_effort')];
        }

        try {
            $response = Http::withToken(config('services.openai.key'))
                ->timeout(config('services.openai.timeout'))
                ->post(config('services.openai.url').'/responses', $payload)
                ->throw()
                ->json();
        } catch (RequestException $e) {
            report($e);
            throw new AiException('The AI request failed.', previous: $e);
        }

        $text = collect($response['output'] ?? [])
            ->where('type', 'message')
            ->flatMap(fn (array $message) => $message['content'] ?? [])
            ->where('type', 'output_text')
            ->pluck('text')
            ->implode('');

        $data = json_decode($text, true);
        if (! is_array($data)) {
            report(new RuntimeException('Unexpected AI response: '.substr((string) json_encode($response), 0, 500)));
            throw new AiException('The AI answer could not be read.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function text(string $text): array
    {
        return ['type' => 'input_text', 'text' => $text];
    }

    /**
     * Image or PDF as inline data.
     *
     * @return array<string, mixed>
     */
    public static function file(UploadedFile $file): array
    {
        $mime = $file->getMimeType() ?? 'application/octet-stream';
        $data = 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($file->getRealPath()));

        return $mime === 'application/pdf'
            ? ['type' => 'input_file', 'filename' => $file->getClientOriginalName() ?: 'document.pdf', 'file_data' => $data]
            : ['type' => 'input_image', 'image_url' => $data, 'detail' => 'high'];
    }
}
