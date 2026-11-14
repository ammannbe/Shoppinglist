<?php

use App\Ai\OpenAiClient;
use App\Announcements\AccountManagerAnnouncements;
use App\Announcements\AnnouncementDispatcher;
use App\Entitlements\Entitlements;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AnnouncementUnsubscribeController;
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
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\RecipesConnectionController;
use App\Http\Controllers\SyncController;
use App\Http\Middleware\EnsureAiFeature;
use App\Models\Household;
use App\Recipes\RecipesClient;
use App\Support\Seasons;
use App\Support\ShopRegions;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
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

// Unsubscribe link of the announcement mails (signed, no login); POST is also the one-click unsubscribe (RFC 8058).
Route::get('announcements/unsubscribe/{user}', [AnnouncementUnsubscribeController::class, 'show'])
    ->middleware(['signed', 'throttle:30,1'])->name('announcements.unsubscribe');
Route::post('announcements/unsubscribe/{user}', [AnnouncementUnsubscribeController::class, 'store'])
    ->middleware(['signed', 'throttle:30,1'])->withoutMiddleware(ValidateCsrfToken::class);

// Link of the household invitation mail, also for guests (they log in or register first).
Route::get('households/invitations/{token}', [HouseholdInvitationController::class, 'show'])
    ->middleware('throttle:30,1')->name('households.invitations.show');

Route::middleware('auth')->group(function () {
    Route::get('me', fn (Request $request) => [
        'email' => $request->user()->email,
        'name' => $request->user()->name,
        'verified' => $request->user()->hasVerifiedEmail(),
        // Linked to the OIDC login: no password and no passkeys (App\Auth\OidcAccount).
        'linked' => $request->user()->isLinkedToOidc(),
        // "Manage account" in the settings (AccountController).
        'account' => AccountController::state($request->user()),
        // Result of a redirect to the provider (linking, confirming), shown once by the settings.
        'notice' => $request->session()->pull(AccountController::NOTICE),
        'locale' => $request->user()->locale,
        'preferences' => [...PreferenceController::DEFAULTS, ...($request->user()->preferences ?? [])],
        // For "Share with household", and a hint about open invitations in the settings.
        'households' => $request->user()->households()->orderBy('name')->get()
            ->map(fn (Household $household) => ['id' => $household->uuid, 'name' => $household->name])->all(),
        'household_invitations' => $request->user()->householdInvitations()->count(),
        // Shops with regional offers and their regions (for the settings and the offers).
        'regions' => ShopRegions::ALL,
        // Foods in season this month (for the product suggestions and the week planner).
        'season' => ['month' => Seasons::month(), 'foods' => collect(Seasons::names(Seasons::month()))->sort(fn ($a, $b) => strcoll($a, $b))->values()->all()],
        // Unlocked features (App\Entitlements\Entitlements: local setting or the AccountManager).
        'features' => Entitlements::features($request->user()),
        // Week planner (AI): configured and unlocked for the user; locked: configured, but not for this account.
        'assistant' => OpenAiClient::enabledFor($request->user()),
        'assistant_locked' => OpenAiClient::enabled() && ! OpenAiClient::enabledFor($request->user()),
        // Announcements (ANNOUNCEMENTS_DRIVER=account-manager): bell, banner, push (VAPID key) and the email setting.
        'announcements' => AccountManagerAnnouncements::enabled() ? [
            'push_key' => config('webpush.vapid.public_key') ?: null,
            'push_devices' => $request->user()->pushSubscriptions()->count(),
            'emails' => $request->user()->announcement_emails ?? AnnouncementDispatcher::EMAILS_MAINTENANCE,
        ] : null,
        // The connected recipe app, null when not configured.
        'recipes' => RecipesClient::enabled() ? [
            'name' => config('services.recipes.name'),
            'url' => config('services.recipes.url'),
            'connected' => RecipesClient::connected($request->user()),
            'account' => $request->user()->recipes_connection['account'] ?? null,
        ] : null,
    ])->name('me');
    Route::put('preferences', [PreferenceController::class, 'update'])->name('preferences.update');

    // "Manage account" in the settings (JSON, needs a connection); the former account page redirects there.
    Route::get('account', [AccountController::class, 'show'])->name('account');
    Route::delete('account', [AccountController::class, 'destroy'])->middleware('throttle:5,1')->name('account.destroy');
    Route::get('account/passkeys', [AccountController::class, 'passkeys'])->name('account.passkeys');
    Route::post('account/oidc', [OidcController::class, 'link'])->middleware('throttle:5,1')->name('oidc.link');
    Route::delete('account/oidc', [OidcController::class, 'destroy'])->name('oidc.unlink');
    // "Later" on the banner "Link with …" (AUTH_OIDC_NUDGE).
    Route::post('account/oidc-nudge', [AccountController::class, 'dismissOidcNudge'])->middleware('throttle:30,1')->name('oidc.nudge.dismiss');

    // Announcements (bell, list "News", banner) and their settings; read state per user.
    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::post('announcements/read', [AnnouncementController::class, 'mark'])->middleware('throttle:60,1')->name('announcements.mark');
    Route::put('announcements/emails', [AnnouncementController::class, 'updateEmails'])->middleware('throttle:30,1')->name('announcements.emails');
    // Web Push on this device (only used for announcements).
    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:30,1')->name('push.store');
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])->middleware('throttle:30,1')->name('push.destroy');

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

        Route::middleware(['throttle:ai', EnsureAiFeature::class])->prefix('planner')->name('planner.')->group(function () {
            Route::post('offers', [PlannerController::class, 'offers'])->name('offers');
            Route::post('pantry', [PlannerController::class, 'pantry'])->name('pantry');
            Route::post('suggestions', [PlannerController::class, 'suggestions'])->name('suggestions');
            Route::post('list', [PlannerController::class, 'list'])->name('list');
            Route::post('recipes', [PlannerController::class, 'saveRecipe'])->name('recipes');
        });

        // Own recipes by name for the planner (no AI request).
        Route::post('planner/recipe-search', [PlannerController::class, 'searchRecipes'])->middleware('throttle:60,1')->name('planner.recipe-search');

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

        // Households (server rendered, HOUSEHOLDS_DRIVER=account-manager: read only).
        Route::get('households', [HouseholdController::class, 'index'])->name('households.index');
        Route::post('households', [HouseholdController::class, 'store'])->name('households.store');
        Route::get('households/{household}', [HouseholdController::class, 'show'])->name('households.show');
        Route::patch('households/{household}', [HouseholdController::class, 'update'])->name('households.update');
        Route::delete('households/{household}', [HouseholdController::class, 'destroy'])->name('households.destroy');
        Route::post('households/{household}/invitations', [HouseholdInvitationController::class, 'store'])->name('households.invitations.store');
        Route::delete('households/{household}/invitations/{invitation}', [HouseholdInvitationController::class, 'destroy'])
            ->scopeBindings()->name('households.invitations.destroy');
        // Open invitations for the user, on the households page.
        Route::post('households/invitations/{invitation}/accept', [HouseholdInvitationController::class, 'acceptOpen'])
            ->whereNumber('invitation')->middleware('throttle:30,1')->name('households.invitations.accept-open');
        Route::delete('households/invitations/{invitation}', [HouseholdInvitationController::class, 'decline'])
            ->whereNumber('invitation')->name('households.invitations.decline');
        Route::patch('households/{household}/members/{member}', [HouseholdMemberController::class, 'update'])->name('households.members.update');
        Route::delete('households/{household}/members/{member}', [HouseholdMemberController::class, 'destroy'])->name('households.members.destroy');
    });
});
