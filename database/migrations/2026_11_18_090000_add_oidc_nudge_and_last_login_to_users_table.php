<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * oidc_nudge_dismissed_at: "Later" on the banner "Link with …" (App\Auth\OidcNudge).
     * last_login_at: reported to the AccountManager (App\Households\AccountManagerUsers).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('oidc_nudge_dismissed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['oidc_nudge_dismissed_at', 'last_login_at']);
        });
    }
};
