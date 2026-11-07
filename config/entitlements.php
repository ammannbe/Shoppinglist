<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Entitlements
    |--------------------------------------------------------------------------
    |
    | "local": every user has the local features below and access to the app.
    | "account-manager": access status and features of users with a linked
    | sign-in (oidc_sub) come from the AccountManager (contract: AccountManager
    | docs/ENTITLEMENTS-API.md): the last snapshot per user is stored in
    | user_entitlements (webhook entitlements.changed, sync on the OIDC login
    | and daily entitlements:sync). A suspended or missing access blocks the
    | app for that user. Users without a linked sign-in (e.g. emergency addresses)
    | keep the local features. Uses the ACCOUNT_MANAGER_* values of
    | config/households.php.
    |
    */

    'driver' => env('ENTITLEMENTS_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Admin rights
    |--------------------------------------------------------------------------
    |
    | This app has no admin features yet, the setting is prepared for them.
    | "account-manager": linked users are admins when the AccountManager says
    | so ("Admin im Dienst"). The emergency addresses (config/login.php)
    | count as admins with both settings.
    |
    */

    'admin_source' => env('AUTH_ADMIN_SOURCE', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Local features
    |--------------------------------------------------------------------------
    |
    | Feature keys every user has with the local driver, and users without a
    | linked sign-in with the account-manager driver (comma separated).
    | shoppinglist.ai: week planner and recipes (AI, also needs OPENAI_API_KEY).
    |
    */

    'local' => [
        'features' => array_values(array_filter(array_map('trim', explode(',', (string) env('ENTITLEMENTS_LOCAL_FEATURES', 'shoppinglist.ai'))))),
    ],

];
