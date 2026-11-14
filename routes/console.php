<?php

use App\Models\AccountManagerWebhookEvent;
use App\Models\Offer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Production runs two cron jobs every minute (see README):
// `php artisan schedule:run` and `php artisan queue:work --max-time=50` (mails).

Artisan::command('offers:prune', function () {
    $pruned = Offer::query()
        ->whereDate('valid_until', '<', today())
        ->orWhere(fn ($q) => $q->whereNull('valid_until')->where('created_at', '<', now()->subDays(Offer::UNDATED_DAYS)))
        ->delete();
    $this->info("Pruned {$pruned} offer(s).");
})->purpose('Remove expired offers and undated ones after '.Offer::UNDATED_DAYS.' days');

Schedule::command('offers:prune')->daily();

// HOUSEHOLDS_DRIVER=account-manager: fallback for lost webhooks, and the state of proposed local households.
Schedule::command('households:sync')->dailyAt('04:15')->withoutOverlapping();
Schedule::command('households:import --check')->hourly()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [AccountManagerWebhookEvent::class]])->daily();

// ENTITLEMENTS_DRIVER / AUTH_ADMIN_SOURCE=account-manager: fallback for lost entitlements.changed webhooks.
Schedule::command('entitlements:sync')->dailyAt('04:30')->withoutOverlapping();

// ACCOUNT_MANAGER_REPORT_USERS: the full list of users (catches up lost single reports, removes deleted users).
Schedule::command('account-manager:report-users')->dailyAt('04:45')->withoutOverlapping();

// ANNOUNCEMENTS_DRIVER=account-manager: changes every 15 minutes (fallback for lost announcements.changed webhooks),
// the complete list daily (removes copies the AccountManager no longer lists).
Schedule::command('account-manager:sync-announcements')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('account-manager:sync-announcements --full')->dailyAt('04:50')->withoutOverlapping();
