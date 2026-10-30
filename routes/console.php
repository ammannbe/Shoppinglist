<?php

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
