<?php

use App\Ai\OpenAiClient;
use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\HouseholdInvitationController;
use App\Http\Controllers\HouseholdMemberController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\ListHouseholdController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\PlannedRecipeController;
use App\Http\Controllers\PlannerController;
use App\Http\Controllers\PlannerStateController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\RecipesConnectionController;
use App\Http\Controllers\SyncController;
use App\Models\Household;
use App\Recipes\RecipesClient;
use App\Support\Seasons;
use App\Support\ShopRegions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// App shell: public and cacheable by the service worker, all data is loaded client side.
Route::view('/', 'app')->name('home');

Route::put('locale', [LocaleController::class, 'update'])->name('locale.update');

// Optional OpenID Connect login (config/login.php).
Route::middleware('throttle:20,1')->group(function () {
    Route::get('auth/oidc/redirect', [OidcController::class, 'redirect'])->name('oidc.redirect');
    Route::get('auth/oidc/callback', [OidcController::class, 'callback'])->name('oidc.callback');
});

// Link of the household invitation mail, also for guests (they log in or register first).
Route::get('households/invitations/{token}', [HouseholdInvitationController::class, 'show'])
    ->middleware('throttle:30,1')->name('households.invitations.show');

Route::middleware('auth')->group(function () {
    Route::get('me', fn (Request $request) => [
        'email' => $request->user()->email,
        'name' => $request->user()->name,
        'verified' => $request->user()->hasVerifiedEmail(),
        'locale' => $request->user()->locale,
        'preferences' => [...PreferenceController::DEFAULTS, ...($request->user()->preferences ?? [])],
        // For "Share with household".
        'households' => $request->user()->households()->orderBy('name')->get()
            ->map(fn (Household $household) => ['id' => $household->uuid, 'name' => $household->name])->all(),
        // Shops with regional offers and their regions (for the settings and the offers).
        'regions' => ShopRegions::ALL,
        // Foods in season this month (for the product suggestions and the week planner).
        'season' => ['month' => Seasons::month(), 'foods' => collect(Seasons::names(Seasons::month()))->sort(fn ($a, $b) => strcoll($a, $b))->values()->all()],
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
    Route::delete('account/oidc', [OidcController::class, 'destroy'])->name('oidc.unlink');
    Route::get('account/passkeys', fn (Request $request) => view('account-passkeys', [
        'passkeys' => $request->user()->passkeys()->latest()->get(),
    ]))->middleware('password.confirm')->name('account.passkeys');

    // Accepting proves the address (the link came by mail), so unverified users may accept.
    Route::post('households/invitations/{token}', [HouseholdInvitationController::class, 'accept'])
        ->middleware('throttle:30,1')->name('households.invitations.accept');

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

        // Planner state across devices (no AI request, not rate limited like the planner).
        Route::get('planner/state', [PlannerStateController::class, 'show'])->name('planner.state.show');
        Route::put('planner/state', [PlannerStateController::class, 'update'])->name('planner.state.update');

        Route::get('planned-recipes', [PlannedRecipeController::class, 'index'])->name('planned-recipes.index');
        Route::post('planned-recipes', [PlannedRecipeController::class, 'store'])->name('planned-recipes.store');
        Route::post('planned-recipes/used', [PlannedRecipeController::class, 'used'])->name('planned-recipes.used');
        Route::get('planned-recipes/{plannedRecipe}', [PlannedRecipeController::class, 'show'])->name('planned-recipes.show');
        Route::post('planned-recipes/{plannedRecipe}/plan', [PlannedRecipeController::class, 'plan'])->name('planned-recipes.plan');
        Route::delete('planned-recipes/{plannedRecipe}/plan', [PlannedRecipeController::class, 'unplan'])->name('planned-recipes.unplan');
        Route::delete('planned-recipes', [PlannedRecipeController::class, 'destroyAll'])->name('planned-recipes.destroy-all');
        Route::delete('planned-recipes/{plannedRecipe}', [PlannedRecipeController::class, 'destroy'])->name('planned-recipes.destroy');

        Route::get('offers', [OfferController::class, 'index'])->name('offers.index');
        Route::post('offers', [OfferController::class, 'store'])->name('offers.store');
        Route::patch('offers/{offer}', [OfferController::class, 'update'])->name('offers.update');
        Route::delete('offers', [OfferController::class, 'destroyStore'])->name('offers.destroy-store');
        Route::delete('offers/{offer}', [OfferController::class, 'destroy'])->name('offers.destroy');

        Route::post('lists/{list}/members', [MemberController::class, 'store'])->name('members.store');
        Route::delete('lists/{list}/members/{email}', [MemberController::class, 'destroy'])->name('members.destroy');
        Route::post('lists/{list}/households', [ListHouseholdController::class, 'store'])->name('list-households.store');
        Route::delete('lists/{list}/households/{household}', [ListHouseholdController::class, 'destroy'])->name('list-households.destroy');

        // Households (server rendered, HOUSEHOLDS_DRIVER=konto: read only).
        Route::get('households', [HouseholdController::class, 'index'])->name('households.index');
        Route::post('households', [HouseholdController::class, 'store'])->name('households.store');
        Route::get('households/{household}', [HouseholdController::class, 'show'])->name('households.show');
        Route::patch('households/{household}', [HouseholdController::class, 'update'])->name('households.update');
        Route::delete('households/{household}', [HouseholdController::class, 'destroy'])->name('households.destroy');
        Route::post('households/{household}/invitations', [HouseholdInvitationController::class, 'store'])->name('households.invitations.store');
        Route::delete('households/{household}/invitations/{invitation}', [HouseholdInvitationController::class, 'destroy'])
            ->scopeBindings()->name('households.invitations.destroy');
        Route::patch('households/{household}/members/{member}', [HouseholdMemberController::class, 'update'])->name('households.members.update');
        Route::delete('households/{household}/members/{member}', [HouseholdMemberController::class, 'destroy'])->name('households.members.destroy');
    });
});
