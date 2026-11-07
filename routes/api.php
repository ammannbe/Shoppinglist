<?php

use App\Http\Controllers\Api\KontoWebhookController;
use Illuminate\Support\Facades\Route;

// Household events of the AccountManager (HOUSEHOLDS_DRIVER=konto), signed, no session or CSRF.
Route::post('konto/webhook', KontoWebhookController::class)->middleware('throttle:120,1')->name('konto.webhook');
