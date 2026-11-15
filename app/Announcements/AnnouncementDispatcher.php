<?php

namespace App\Announcements;

use App\Jobs\SendAnnouncementBatch;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Sends new announcements by Web Push and email, once per announcement and channel: the first run
 * claims the channel (push_dispatched_at / email_dispatched_at, atomic update), then queues the
 * recipients in small packages (SendAnnouncementBatch, one delivery report each). Each user is
 * claimed again per channel in announcement_deliveries, so overlapping runs never send twice.
 */
class AnnouncementDispatcher
{
    public const PUSH = 'push';

    public const EMAIL = 'email';

    public const CHANNELS = [self::PUSH, self::EMAIL];

    /** Email setting of the users (users.announcement_emails). */
    public const EMAILS_ALL = 'all';

    public const EMAILS_MAINTENANCE = 'maintenance';

    public const EMAILS_NONE = 'none';

    public const EMAIL_SETTINGS = [self::EMAILS_ALL, self::EMAILS_MAINTENANCE, self::EMAILS_NONE];

    public function dispatchDue(): void
    {
        Announcement::query()->active()
            ->where(fn (Builder $query) => $query->whereNull('push_dispatched_at')->orWhereNull('email_dispatched_at'))
            ->orderBy('publish_at')
            ->get()
            ->each(function (Announcement $announcement): void {
                foreach (self::CHANNELS as $channel) {
                    if ($announcement->hasChannel($channel) && $this->claim($announcement, $channel)) {
                        $this->queue($announcement, $channel);
                    }
                }
            });
    }

    /**
     * True for the one run that may send the channel of the announcement.
     */
    private function claim(Announcement $announcement, string $channel): bool
    {
        $column = "{$channel}_dispatched_at";
        if ($announcement->{$column} !== null) {
            return false;
        }

        return Announcement::query()->whereKey($announcement->getKey())->whereNull($column)->update([$column => now()]) === 1;
    }

    private function queue(Announcement $announcement, string $channel): void
    {
        // First seen long after the publication (e.g. the first sync after enabling the driver): not sent.
        $maxAge = max(1, (int) config('announcements.send_max_age_days'));
        if ($announcement->publish_at->lt(now()->subDays($maxAge))) {
            return;
        }

        $batch = 0;
        self::recipients($announcement, $channel)->select('users.id')->chunkById(
            max(1, (int) config('announcements.batch_size')),
            function (Collection $users) use ($announcement, $channel, &$batch): void {
                SendAnnouncementBatch::dispatch($announcement->id, $channel, $users->pluck('id')->all(), $channel.'-'.(++$batch));
            },
            'users.id',
            'id',
        );
    }

    /**
     * Users who get the announcement by the channel: registered before its publication (maintenance
     * notices: everybody), push with a subscribed device, email to verified addresses with the setting
     * "all" (maintenance notices also "maintenance", the default; "none" = unsubscribed).
     *
     * @return Builder<User>
     */
    public static function recipients(Announcement $announcement, string $channel): Builder
    {
        $query = User::query();
        if (! $announcement->isMaintenance()) {
            $query->where('created_at', '<=', $announcement->publish_at);
        }

        if ($channel === self::PUSH) {
            return $query->whereHas('pushSubscriptions');
        }

        return $query->whereNotNull('email_verified_at')
            ->whereIn('announcement_emails', $announcement->isMaintenance() ? [self::EMAILS_ALL, self::EMAILS_MAINTENANCE] : [self::EMAILS_ALL]);
    }
}
