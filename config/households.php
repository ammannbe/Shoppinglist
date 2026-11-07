<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Households
    |--------------------------------------------------------------------------
    |
    | Households share lists with all their members. "local": households are
    | managed in this app (create, invite by email, roles). "account-manager": they are
    | mirrored from the AccountManager (webhooks plus a daily sync, matched by
    | oidc_sub), the local management is read only. Sharing a list with a
    | household works with both drivers.
    |
    */

    'driver' => env('HOUSEHOLDS_DRIVER', 'local'),

    // Days an invitation link stays valid.
    'invitation_days' => 14,

    /*
    |--------------------------------------------------------------------------
    | AccountManager ("Konto")
    |--------------------------------------------------------------------------
    |
    | Contract: AccountManager docs/HOUSEHOLDS-API.md. The secret of the
    | service signs the webhooks (POST /api/account-manager/webhook) and authenticates
    | the API calls (HTTP Basic with the service key). report_shares: tell the
    | AccountManager which lists are shared with a household (overview only).
    | label: name of the AccountManager in the household texts; empty: the name it
    | publishes at /api/info (App\Households\AccountManagerLabel), otherwise "Account".
    |
    */

    'account_manager' => [
        'url' => env('ACCOUNT_MANAGER_URL'),
        'key' => env('ACCOUNT_MANAGER_SERVICE_KEY'),
        'secret' => env('ACCOUNT_MANAGER_SECRET'),
        'report_shares' => (bool) env('ACCOUNT_MANAGER_REPORT_SHARES', true),
        'label' => env('ACCOUNT_MANAGER_LABEL'),
    ],

];
