<?php

namespace App\Auth;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Banner "Link with …" in the app (AUTH_OIDC_NUDGE, config/login.php) for logged-in users whose account
 * is not linked to the OpenID Connect login. "Later" sets users.oidc_nudge_dismissed_at; it comes back
 * AUTH_OIDC_NUDGE_DAYS later, never again without days. The button starts the link flow of the settings.
 * The app shell decides offline from the state in /me (resources/js/oidc-nudge.js).
 */
class OidcNudge
{
    public static function enabled(): bool
    {
        return config('login.oidc.enabled') && config('login.oidc.nudge');
    }

    public static function days(): ?int
    {
        $days = (int) config('login.oidc.nudge_days');

        return $days > 0 ? $days : null;
    }

    /**
     * The user may get the banner (enabled and not linked), shown or dismissed.
     */
    public static function applies(User $user): bool
    {
        return self::enabled() && ! $user->isLinkedToOidc();
    }

    /**
     * The banner is shown now.
     */
    public static function shows(User $user, ?CarbonInterface $now = null): bool
    {
        if (! self::applies($user)) {
            return false;
        }

        $dismissed = $user->oidc_nudge_dismissed_at;
        if ($dismissed === null) {
            return true;
        }

        $days = self::days();

        return $days !== null && ($now ?? now())->greaterThanOrEqualTo($dismissed->copy()->addDays($days));
    }

    /**
     * For /me (account.oidc.nudge): null without banner, otherwise the days and the dismissal (ms).
     *
     * @return array{days: int|null, dismissed_at: int|null}|null
     */
    public static function state(User $user): ?array
    {
        if (! self::applies($user)) {
            return null;
        }

        return [
            'days' => self::days(),
            'dismissed_at' => $user->oidc_nudge_dismissed_at?->getTimestampMs(),
        ];
    }

    public static function dismiss(User $user): void
    {
        $user->forceFill(['oidc_nudge_dismissed_at' => now()])->save();
    }
}
