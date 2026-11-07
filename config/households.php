<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Households
    |--------------------------------------------------------------------------
    |
    | Households share lists with all their members. "local": households are
    | managed in this app (create, invite by email, roles). "konto": they are
    | mirrored from the AccountManager (not implemented yet), the local
    | management is read only. Sharing a list with a household works with
    | both drivers.
    |
    */

    'driver' => env('HOUSEHOLDS_DRIVER', 'local'),

    // Days an invitation link stays valid.
    'invitation_days' => 14,

];
