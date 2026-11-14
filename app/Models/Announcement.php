<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Local copy of an announcement of the AccountManager (docs/ANNOUNCEMENTS-API.md there).
 * Texts per language (de, en; null when missing, the other one is used).
 *
 * @property array<string, string|null> $title
 * @property array<string, string|null> $body_html
 * @property array<string, string|null> $excerpt
 * @property list<string> $channels
 */
class Announcement extends Model
{
    public const TYPES = ['feature', 'info', 'maintenance'];

    public const MAINTENANCE = 'maintenance';

    public const CHANNELS = ['in_app', 'banner', 'push', 'email'];

    public const LANGUAGES = ['de', 'en'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'body_html' => 'array',
            'excerpt' => 'array',
            'channels' => 'array',
            'publish_at' => 'datetime',
            'expires_at' => 'datetime',
            'remote_updated_at' => 'datetime',
            'push_dispatched_at' => 'datetime',
            'email_dispatched_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AnnouncementRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    /**
     * @return HasMany<AnnouncementDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(AnnouncementDelivery::class);
    }

    public function hasChannel(string $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    public function isMaintenance(): bool
    {
        return $this->type === self::MAINTENANCE;
    }

    public function isActive(): bool
    {
        return $this->publish_at->isPast() && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Published and not expired.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('publish_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Active and meant for the user: users do not see announcements published before their
     * registration, except maintenance notices.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->active()->where(fn (Builder $query) => $query
            ->where('type', self::MAINTENANCE)
            ->orWhere('publish_at', '>=', $user->created_at));
    }

    /**
     * Language of the app ("de_CH", "en") as language of the announcements ("de", "en").
     */
    public static function language(?string $locale): string
    {
        return str_starts_with((string) ($locale ?: config('app.locale')), 'en') ? 'en' : 'de';
    }

    /**
     * Text of a field in the language, otherwise the other language.
     *
     * @param  'title'|'body_html'|'excerpt'  $field
     */
    public function text(string $field, ?string $locale): ?string
    {
        $values = $this->{$field} ?? [];
        $language = self::language($locale);
        $value = $values[$language] ?? null;
        if (filled($value)) {
            return $value;
        }

        foreach (self::LANGUAGES as $other) {
            if (filled($values[$other] ?? null)) {
                return $values[$other];
            }
        }

        return null;
    }

    /**
     * For the app (GET /announcements), in the user's language.
     *
     * @return array<string, mixed>
     */
    public function toClient(User $user, ?AnnouncementRead $read): array
    {
        return [
            'id' => $this->account_manager_uuid,
            'type' => $this->type,
            'title' => $this->text('title', $user->locale),
            'body_html' => $this->text('body_html', $user->locale),
            'excerpt' => $this->text('excerpt', $user->locale),
            'link' => $this->link,
            'image_url' => $this->image_url,
            'published_at' => $this->publish_at->getTimestampMs(),
            'expires_at' => $this->expires_at?->getTimestampMs(),
            'in_app' => $this->hasChannel('in_app'),
            'banner' => $this->hasChannel('banner'),
            'read_at' => $read?->read_at?->getTimestampMs(),
            'dismissed_at' => $read?->dismissed_at?->getTimestampMs(),
        ];
    }
}
