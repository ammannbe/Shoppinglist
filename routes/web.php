<?php

use App\Ai\OpenAiClient;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PlannerController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\RecipesConnectionController;
use App\Http\Controllers\SyncController;
use App\Recipes\RecipesClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// App shell: public and cacheable by the service worker, all data is loaded client side.
Route::view('/', 'app')->name('home');

Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');

Route::middleware('auth')->group(function () {
    Route::get('me', fn (Request $request) => [
        'email' => $request->user()->email,
        'name' => $request->user()->name,
        'verified' => $request->user()->hasVerifiedEmail(),
        'locale' => $request->user()->locale,
        'preferences' => [...PreferenceController::DEFAULTS, ...($request->user()->preferences ?? [])],
        // Week planner (AI) and the connected recipe app, null when not configured.
        'assistant' => OpenAiClient::enabled(),
        'recipes' => RecipesClient::enabled() ? [
            'name' => config('services.recipes.name'),
            'url' => config('services.recipes.url'),
            'connected' => RecipesClient::connected($request->user()),
            'account' => $request->user()->recipes_connection['account'] ?? null,
        ] : null,
    ])->name('me');
    Route::put('preferences', [PreferenceController::class, 'update'])->name('preferences.update');

    // Server rendered account pages (need a connection).
    Route::get('account', fn (Request $request) => view('account', [
        'passkeyCount' => $request->user()->passkeys()->count(),
    ]))->name('account');
    Route::get('account/passkeys', fn (Request $request) => view('account-passkeys', [
        'passkeys' => $request->user()->passkeys()->latest()->get(),
    ]))->middleware('password.confirm')->name('account.passkeys');

    Route::middleware('verified')->group(function () {
        Route::get('sync/{collection}/pull', [SyncController::class, 'pull'])->name('sync.pull');
        Route::post('sync/{collection}/push', [SyncController::class, 'push'])->name('sync.push');

        Route::get('images/{image}/{size?}', [ImageController::class, 'show'])->whereIn('size', ['thumb'])->name('images.show');
        Route::post('products/{product}/image', [ImageController::class, 'store'])->name('products.image.store');
        Route::delete('products/{product}/image', [ImageController::class, 'destroy'])->name('products.image.destroy');

        Route::get('recipes/connect', [RecipesConnectionController::class, 'connect'])->name('recipes.connect');
        Route::get('recipes/callback', [RecipesConnectionController::class, 'callback'])->name('recipes.callback');
        Route::delete('recipes/connection', [RecipesConnectionController::class, 'destroy'])->name('recipes.disconnect');

        Route::middleware('throttle:ai')->prefix('planner')->name('planner.')->group(function () {
            Route::post('offers', [PlannerController::class, 'offers'])->name('offers');
            Route::post('pantry', [PlannerController::class, 'pantry'])->name('pantry');
            Route::post('suggestions', [PlannerController::class, 'suggestions'])->name('suggestions');
            Route::post('list', [PlannerController::class, 'list'])->name('list');
            Route::post('recipes', [PlannerController::class, 'saveRecipe'])->name('recipes');
        });

        Route::post('lists/{list}/members', [MemberController::class, 'store'])->name('members.store');
        Route::delete('lists/{list}/members/{email}', [MemberController::class, 'destroy'])->name('members.destroy');
    });
});
