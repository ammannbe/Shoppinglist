<?php

use App\Http\Controllers\Api\AccountManagerWebhookController;
use Illuminate\Support\Facades\Route;

// Household events of the AccountManager (HOUSEHOLDS_DRIVER=account-manager), signed, no session or CSRF.
Route::post('account-manager/webhook', AccountManagerWebhookController::class)->middleware('throttle:120,1')->name('account-manager.webhook');
