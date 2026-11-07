<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts linked to the OIDC login become SSO-only (App\Auth\OidcAccount): no password, no
 * passkeys, no remember-me token, no pending password reset and no session from a local login.
 * Unlinked accounts (e.g. the emergency addresses) are not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $linked = DB::table('users')->whereNotNull('oidc_sub');

        // A null remember token matches no cookie; the next OIDC login sets a new one.
        (clone $linked)->update(['password' => null, 'remember_token' => null]);

        DB::table('passkeys')->whereIn('user_id', (clone $linked)->select('id'))->delete();
        DB::table('password_reset_tokens')->whereIn('email', (clone $linked)->select('email'))->delete();

        // The linked users log in again with the provider once.
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->whereIn('user_id', (clone $linked)->select('id'))->delete();
        }
    }

    public function down(): void
    {
        // Passwords and passkeys are gone; unlinked users set a password with "Forgot your password?" (users:unlink-oidc).
    }
};
