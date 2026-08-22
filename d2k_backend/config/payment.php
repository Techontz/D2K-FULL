<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Direct2Kariakoo's own Lipa Namba
    |--------------------------------------------------------------------------
    |
    | One till for the whole marketplace. A basket spanning three sellers is
    | still one order and one payment — the customer is not asked to make three
    | transfers, and D2K settles with each seller afterwards.
    |
    | These are the fallback values, used before an administrator has saved
    | anything in the admin panel (Settings → Payment) and on a server whose
    | `payment_settings` table does not exist yet. The panel is the intended
    | way to change them; this exists so that checkout is never dead merely
    | because a migration has not run.
    |
    */

    'lipa_namba' => env('D2K_LIPA_NAMBA'),

    'account_name' => env('D2K_PAYMENT_ACCOUNT_NAME', 'Direct2Kariakoo'),

    // Lets payment be taken offline deliberately — during a till change, say —
    // rather than by blanking the number and leaving checkout to guess why.
    'active' => env('D2K_PAYMENT_ACTIVE', true),

];
