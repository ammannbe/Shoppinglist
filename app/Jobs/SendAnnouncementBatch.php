<?php

namespace App\Jobs;

use App\Announcements\AccountManagerAnnouncements;
use App\Announcements\AnnouncementDispatcher;
use App\Households\AccountManagerClient;
use App\Households\AccountManagerUnavailable;
use App\Mail\AnnouncementMail;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\User;
use App\Notifications\AnnouncementPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use NotificationChannels\WebPush\WebPushChannel;
use Throwable;

/**
 * Sends an announcement to one package of users by push or email (AnnouncementDispatcher) and reports
 * the numbers of the package to the AccountManager (ANNOUNCEMENTS-API.md 3.3, the batch id replaces
 * the numbers of a retry). Every user is claimed in announcement_deliveries before sending: a retry or
 * an overlapping run skips users already claimed (at most once; a crash between claim and send loses
 * that one message).
 */
class SendAnnouncementBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    /**
     * @param  list<int>  $userIds
     */
    public function __construct(public int $announcementId, public string $channel, public array $userIds, public string $batch) {}

    public function handle(AccountManagerClient $client): void
    {
        $announcement = Announcement::query()->find($this->announcementId);
        // Withdrawn in the meantime, or expired: nothing more is sent.
        if ($announcement === null || ! $announcement->isActive() || ! in_array($this->channel, AnnouncementDispatcher::CHANNELS, true)) {
            return;
        }

        $users = User::query()->whereIn('id', $this->userIds)->get();
        foreach ($users as $user) {
            if (! $this->claim($announcement, $user)) {
                continue;
            }

            AnnouncementDelivery::query()
                ->where(['announcement_id' => $announcement->id, 'user_id' => $user->id, 'channel' => $this->channel])
                ->update(['status' => $this->send($announcement, $user), 'updated_at' => now()]);
        }

        $this->report($client, $announcement);
    }

    private function claim(Announcement $announcement, User $user): bool
    {
        return AnnouncementDelivery::query()->insertOrIgnore([
            'announcement_id' => $announcement->id,
            'user_id' => $user->id,
            'channel' => $this->channel,
            'batch' => $this->batch,
            'created_at' => now(),
            'updated_at' => now(),
        ]) > 0;
    }

    /**
     * @return string|null sent, failed, or null when there was nothing to send to (no device left)
     */
    private function send(Announcement $announcement, User $user): ?string
    {
        try {
            if ($this->channel === AnnouncementDispatcher::EMAIL) {
                Mail::to($user)->send(new AnnouncementMail($announcement, $user));

                return AnnouncementDelivery::SENT;
            }

            // The package's channel: VAPID keys from config/webpush.php, expired subscriptions are deleted.
            $reports = app(WebPushChannel::class)->send($user, new AnnouncementPush($announcement));
            if ($reports === []) {
                return null;
            }

            return collect($reports)->contains(fn ($report) => $report->isSuccess()) ? AnnouncementDelivery::SENT : AnnouncementDelivery::FAILED;
        } catch (Throwable $exception) {
            Log::warning("Announcements: sending {$this->channel} to user {$user->id} failed.", ['announcement' => $announcement->account_manager_uuid, 'exception' => $exception->getMessage()]);

            return AnnouncementDelivery::FAILED;
        }
    }

    /**
     * Numbers of the whole package (also of users sent by an earlier try). Failures only cost the overview.
     */
    private function report(AccountManagerClient $client, Announcement $announcement): void
    {
        if (! AccountManagerAnnouncements::enabled()) {
            return;
        }

        $counts = AnnouncementDelivery::query()
            ->where(['announcement_id' => $announcement->id, 'channel' => $this->channel, 'batch' => $this->batch])
            ->whereNotNull('status')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        try {
            $client->reportDeliveries(
                $announcement->account_manager_uuid,
                $this->channel,
                (int) ($counts[AnnouncementDelivery::SENT] ?? 0),
                (int) ($counts[AnnouncementDelivery::FAILED] ?? 0),
                $this->batch,
            );
        } catch (AccountManagerUnavailable $exception) {
            AccountManagerAnnouncements::logUnavailable($exception, "reporting the deliveries of {$announcement->account_manager_uuid}");
        }
    }
}
