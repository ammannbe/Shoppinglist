<?php

namespace App\Http\Controllers;

use App\Announcements\AnnouncementDispatcher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Signed unsubscribe link of the announcement mails (no login needed). GET shows a confirmation with a
 * button (link scanners do not unsubscribe), POST unsubscribes: from the page and as one-click
 * unsubscribe of the mail clients (List-Unsubscribe-Post, RFC 8058, therefore without CSRF token).
 * Sets users.announcement_emails to "none", also for maintenance notices.
 */
class AnnouncementUnsubscribeController extends Controller
{
    public static function url(User $user): string
    {
        return URL::signedRoute('announcements.unsubscribe', ['user' => $user->getKey()]);
    }

    public function show(User $user): View
    {
        $this->useLocaleOf($user);

        return view('announcements.unsubscribe', [
            'user' => $user,
            'done' => $user->announcement_emails === AnnouncementDispatcher::EMAILS_NONE,
        ]);
    }

    public function store(Request $request, User $user): View|Response
    {
        $user->forceFill(['announcement_emails' => AnnouncementDispatcher::EMAILS_NONE])->save();

        // One-click unsubscribe of a mail client: no page needed.
        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return response()->noContent();
        }

        $this->useLocaleOf($user);

        return view('announcements.unsubscribe', ['user' => $user, 'done' => true]);
    }

    /** The page in the language of the mail. */
    private function useLocaleOf(User $user): void
    {
        if (array_key_exists((string) $user->locale, config('app.locales'))) {
            App::setLocale($user->locale);
        }
    }
}
