<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Web Push of an announcement (title and excerpt), sent by SendAnnouncementBatch through the
 * WebPushChannel; public/push-sw.js shows it and opens the news (or the link) on click.
 */
class AnnouncementPush extends Notification
{
    public function __construct(public Announcement $announcement) {}

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        $locale = $notifiable->locale;

        return (new WebPushMessage)
            ->title((string) $this->announcement->text('title', $locale))
            ->body((string) ($this->announcement->text('excerpt', $locale) ?? ''))
            ->icon('/web-app-manifest-192x192.png')
            ->badge('/favicon-96x96.png')
            ->tag('announcement-'.$this->announcement->account_manager_uuid)
            ->data(['url' => self::path($this->announcement)]);
    }

    /**
     * The news in the app, or the link of an announcement that is not listed there.
     */
    public static function path(Announcement $announcement): string
    {
        if ($announcement->hasChannel('in_app')) {
            return '/#/news/'.$announcement->account_manager_uuid;
        }

        return $announcement->link ?? '/';
    }
}
