<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Stores the time of every login (password, passkey, OIDC, remember cookie) in users.last_login_at;
 * the change reports the user to the AccountManager (App\Models\Concerns\ReportsToAccountManager).
 * Same file as in Chorelist.
 */
class RecordLogin
{
    public function handle(Login $event): void
    {
        if ($event->user instanceof User) {
            // Without touching updated_at (no profile change).
            $event->user->timestamps = false;
            try {
                $event->user->forceFill(['last_login_at' => now()])->save();
            } finally {
                $event->user->timestamps = true;
            }
        }
    }
}
