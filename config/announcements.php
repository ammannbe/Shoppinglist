<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Announcements
    |--------------------------------------------------------------------------
    |
    | News, hints and maintenance notices for the users. "none" (default, also
    | "local"): no announcements. "account-manager": mirrored from the AccountManager
    | (contract: AccountManager docs/ANNOUNCEMENTS-API.md) with the ACCOUNT_MANAGER_*
    | values of config/households.php; synced every 15 minutes, daily in full and
    | right away on the webhook announcements.changed. The app shows them (bell,
    | banner) and sends them by Web Push and email to the users who opted in.
    |
    */

    'driver' => env('ANNOUNCEMENTS_DRIVER', 'none'),

    // Push and email are only sent for announcements first seen within this many days after their
    // publication, so the first sync after enabling the driver does not send old news again.
    'send_max_age_days' => (int) env('ANNOUNCEMENTS_SEND_MAX_AGE_DAYS', 7),

    // Recipients per queued job (one delivery report per job).
    'batch_size' => 50,

];
