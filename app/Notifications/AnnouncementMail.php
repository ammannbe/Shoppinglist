<?php

namespace App\Notifications;

use App\Http\Controllers\AnnouncementUnsubscribeController;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Symfony\Component\Mime\Email;

/**
 * An announcement by email (SendAnnouncementBatch, already queued per package). The body is the
 * body_html of the AccountManager (sanitized there). Every mail has a signed unsubscribe link and the
 * List-Unsubscribe headers (one click, RFC 8058).
 */
class AnnouncementMail extends Notification
{
    public function __construct(public Announcement $announcement) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->locale;
        $unsubscribe = AnnouncementUnsubscribeController::url($notifiable);
        $body = $this->announcement->text('body_html', $locale);

        $message = (new MailMessage)
            ->subject((string) $this->announcement->text('title', $locale))
            ->greeting((string) $this->announcement->text('title', $locale));

        if (filled($body)) {
            $message->line(new HtmlString($body));
        } elseif (filled($excerpt = $this->announcement->text('excerpt', $locale))) {
            $message->line($excerpt);
        }

        if ($this->announcement->image_url) {
            $message->line(new HtmlString('<img src="'.e($this->announcement->image_url).'" alt="" style="max-width: 100%; height: auto;">'));
        }

        $message->action(
            $this->announcement->link ? __('Learn more') : __('Open :app', ['app' => config('app.name')]),
            $this->announcement->link ?? url(AnnouncementPush::path($this->announcement)),
        );

        return $message
            ->salutation(new HtmlString(e(__('You get this email because of your settings in :app.', ['app' => config('app.name')]))
                .' <a href="'.e($unsubscribe).'">'.e(__('Unsubscribe from news by email')).'</a>'))
            ->withSymfonyMessage(function (Email $email) use ($unsubscribe): void {
                $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$unsubscribe.'>');
                $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }
}
