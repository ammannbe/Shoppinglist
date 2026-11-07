<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Households
    |--------------------------------------------------------------------------
    |
    | Households share lists with all their members. "local": households are
    | managed in this app (create, invite by email, roles). "konto": they are
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
    | service signs the webhooks (POST /api/konto/webhook) and authenticates
    | the API calls (HTTP Basic with the service key). report_shares: tell the
    | AccountManager which lists are shared with a household (overview only).
    |
    */

    'konto' => [
        'url' => env('KONTO_URL'),
        'key' => env('KONTO_SERVICE_KEY'),
        'secret' => env('KONTO_SECRET'),
        'report_shares' => (bool) env('KONTO_REPORT_SHARES', true),
    ],

];
