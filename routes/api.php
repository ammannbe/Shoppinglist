<?php

use App\Http\Controllers\Api\AccountManagerWebhookController;
use Illuminate\Support\Facades\Route;

// Events of the AccountManager (households, entitlements, user.deleted), signed, no session or CSRF.
Route::post('account-manager/webhook', AccountManagerWebhookController::class)->middleware('throttle:120,1')->name('account-manager.webhook');
