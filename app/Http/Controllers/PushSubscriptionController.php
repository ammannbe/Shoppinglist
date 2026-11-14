<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Web Push subscriptions of the user's devices (one per browser). In this app push is only used for
 * announcements: a subscribed device is the consent (settings "News", resources/js/push.js). Same as Chorelist.
 */
class PushSubscriptionController extends Controller
{
    /** Hosts of the browsers' push services; the server sends requests to the endpoint (no SSRF). */
    public const PUSH_HOSTS = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        '*.push.apple.com',
        '*.notify.windows.com',
    ];

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:500', function (string $attribute, mixed $value, \Closure $fail) {
                if (! self::isPushService($value)) {
                    $fail(__('Unknown push service.'));
                }
            }],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'max:20'],
        ]);

        $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['content_encoding'] ?? 'aes128gcm',
        );

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $request->validate(['endpoint' => ['required', 'string', 'max:500']]);
        $request->user()->deletePushSubscription($request->input('endpoint'));

        return response()->noContent();
    }

    public static function isPushService(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? null) !== 'https' || isset($parts['port']) || isset($parts['user'])) {
            return false;
        }

        return Str::is(self::PUSH_HOSTS, Str::lower($parts['host'] ?? ''));
    }
}
