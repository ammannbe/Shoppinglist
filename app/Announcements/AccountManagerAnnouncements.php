<?php

namespace App\Announcements;

use App\Households\AccountManagerClient;
use App\Households\AccountManagerUnavailable;
use App\Models\Announcement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors the announcements of the AccountManager (ANNOUNCEMENTS_DRIVER=account-manager, contract:
 * AccountManager docs/ANNOUNCEMENTS-API.md): local copy upserted by uuid, `deleted` removes copies,
 * a full sync (without since) removes the ones missing in the complete list. The next since is the
 * server_time of the last successful answer. New announcements are then dispatched by push and email.
 */
class AccountManagerAnnouncements
{
    public const SERVER_TIME_KEY = 'announcements.server_time';

    private const LOCK = 'announcements.sync';

    public function __construct(private AccountManagerClient $client, private AnnouncementDispatcher $dispatcher) {}

    public static function enabled(): bool
    {
        return config('announcements.driver') === 'account-manager' && AccountManagerClient::configured();
    }

    /**
     * Fetches and applies the changes (full: the complete list). Null if another sync is running.
     *
     * @return array{upserted: int, deleted: int}|null
     *
     * @throws AccountManagerUnavailable
     */
    public function sync(bool $full = false): ?array
    {
        $lock = Cache::lock(self::LOCK, 300);
        if (! $lock->get()) {
            return null;
        }

        try {
            $since = $full ? null : Cache::get(self::SERVER_TIME_KEY);
            $answer = $this->client->announcements(is_string($since) ? $since : null);
            $result = DB::transaction(fn () => $this->apply($answer, complete: ! is_string($since)));
            Cache::forever(self::SERVER_TIME_KEY, $answer['server_time']);
        } finally {
            $lock->release();
        }

        $this->dispatcher->dispatchDue();

        return $result;
    }

    /**
     * @param  array{data: list<array<string, mixed>>, deleted: list<string>, server_time: string}  $answer
     * @return array{upserted: int, deleted: int}
     */
    private function apply(array $answer, bool $complete): array
    {
        $upserted = 0;
        $seen = [];

        foreach ($answer['data'] as $raw) {
            try {
                $data = self::valid($raw);
            } catch (ValidationException $exception) {
                Log::warning('Announcements: skipped an invalid announcement of the AccountManager.', ['uuid' => $raw['uuid'] ?? null, 'errors' => $exception->errors()]);

                continue;
            }
            $seen[] = $data['account_manager_uuid'];

            $existing = Announcement::query()->where('account_manager_uuid', $data['account_manager_uuid'])->first();
            if ($existing?->remote_updated_at?->equalTo($data['remote_updated_at'])) {
                continue;
            }

            Announcement::query()->updateOrCreate(['account_manager_uuid' => $data['account_manager_uuid']], $data);
            $upserted++;
        }

        $deleted = Announcement::query()->whereIn('account_manager_uuid', array_map('strtolower', $answer['deleted']))->delete();

        // Without since the list is complete: copies missing there are no longer visible to this service.
        if ($complete) {
            $deleted += Announcement::query()->whereNotIn('account_manager_uuid', $seen)->delete();
        }

        return ['upserted' => $upserted, 'deleted' => $deleted];
    }

    /**
     * Attributes of the local copy from an announcement of the API (3.1). Unknown types count as info,
     * unknown channels are left out, texts are kept per language (de, en).
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function valid(mixed $raw): array
    {
        $data = Validator::make(is_array($raw) ? $raw : [], [
            'uuid' => ['required', 'uuid'],
            'type' => ['nullable', 'string', 'max:50'],
            'title' => ['required', 'array'],
            'body_html' => ['nullable', 'array'],
            'excerpt' => ['nullable', 'array'],
            'channels' => ['present', 'array'],
            'link' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'image_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'publish_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date'],
            'updated_at' => ['required', 'date'],
        ])->validate();

        $texts = fn (?array $values, int $max): array => collect(Announcement::LANGUAGES)
            ->mapWithKeys(fn (string $language) => [$language => is_string($values[$language] ?? null) && trim($values[$language]) !== ''
                ? mb_substr($values[$language], 0, $max)
                : null])
            ->all();

        $title = $texts($data['title'], 150);
        if (! array_filter($title)) {
            throw ValidationException::withMessages(['title' => 'The announcement has no title.']);
        }

        return [
            'account_manager_uuid' => strtolower($data['uuid']),
            'type' => in_array($data['type'] ?? null, Announcement::TYPES, true) ? $data['type'] : 'info',
            'title' => $title,
            'body_html' => $texts($data['body_html'] ?? null, 100_000),
            'excerpt' => $texts($data['excerpt'] ?? null, 160),
            'channels' => array_values(array_intersect(Announcement::CHANNELS, array_filter($data['channels'], 'is_string'))),
            'link' => $data['link'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'publish_at' => self::time($data['publish_at']),
            'expires_at' => isset($data['expires_at']) ? self::time($data['expires_at']) : null,
            'remote_updated_at' => self::time($data['updated_at']),
        ];
    }

    private static function time(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
    }

    public static function logUnavailable(AccountManagerUnavailable $exception, string $context): void
    {
        Log::warning("Announcements: {$context} failed, keeping the last state.", ['exception' => $exception->getMessage()]);
    }
}
