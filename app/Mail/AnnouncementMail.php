<?php

namespace App\Mail;

use App\Http\Controllers\AnnouncementUnsubscribeController;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementPush;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Email of an announcement (App\Jobs\SendAnnouncementBatch sends it within the queued batch, so it is no
 * ShouldQueue). body_html is sanitized by the AccountManager. Button "Learn more" (link) or "Open :app" (the
 * news in the app), signed unsubscribe link plus List-Unsubscribe (one-click, RFC 8058). Same mail as in
 * Chorelist (views mail/announcement*.blade.php).
 */
class AnnouncementMail extends Mailable
{
    public function __construct(public Announcement $announcement, public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) $this->announcement->text('title', $this->language()));
    }

    public function content(): Content
    {
        $locale = $this->language();
        $body = (string) $this->announcement->text('body_html', $locale);
        $bodyHtml = filled($body) ? $body : e((string) $this->announcement->text('excerpt', $locale));
        [$actionText, $actionUrl] = $this->action();

        return new Content(
            markdown: 'mail.announcement',
            text: 'mail.announcement-text',
            with: [
                'title' => (string) $this->announcement->text('title', $locale),
                'bodyHtml' => $bodyHtml,
                'bodyText' => self::plainText($bodyHtml),
                'imageUrl' => $this->announcement->image_url,
                'actionText' => $actionText,
                'actionUrl' => $actionUrl,
                'unsubscribeUrl' => self::unsubscribeUrl($this->user),
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.self::unsubscribeUrl($this->user).'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    /**
     * Signed, without expiry: the link of an old mail still works.
     */
    public static function unsubscribeUrl(User $user): string
    {
        return AnnouncementUnsubscribeController::url($user);
    }

    /**
     * The text version of the mail: body_html without tags, paragraphs kept.
     */
    public static function plainText(string $html): string
    {
        $text = preg_replace(['#<br\s*/?>#i', '#</(p|div|h[1-6]|li|blockquote|pre)>#i', '#<li[^>]*>#i'], ["\n", "\n\n", '- '], $html) ?? $html;

        return trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)) ?? '');
    }

    /**
     * The button: the link of the announcement, otherwise the news in the app (none without either).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function action(): array
    {
        if ($this->announcement->link) {
            return [__('Learn more'), $this->announcement->link];
        }
        if ($this->announcement->hasChannel('in_app')) {
            return [__('Open :app', ['app' => config('app.name')]), url(AnnouncementPush::path($this->announcement))];
        }

        return [null, null];
    }

    private function language(): string
    {
        return $this->locale ?? $this->user->preferredLocale() ?? (string) config('app.locale');
    }
}
