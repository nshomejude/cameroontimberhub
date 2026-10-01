<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Indicative FX conversion
    |--------------------------------------------------------------------------
    |
    | Suppliers quote in FCFA (XAF). The product page may show an ADDITIONAL,
    | clearly indicative USD figure beside the quoted price — but only when the
    | operator has configured a rate they stand behind. There is deliberately no
    | default: with no rate configured the USD line is hidden entirely rather
    | than showing an invented number.
    |
    | Value is USD per 1 XAF (e.g. 0.00166).
    |
    */

    'fx' => [
        'usd_per_xaf' => filled(env('TIMBER_FX_USD_PER_XAF'))
            ? (float) env('TIMBER_FX_USD_PER_XAF')
            : null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-service signup options
    |--------------------------------------------------------------------------
    |
    | The carbon account types (carbon_developer, carbon_buyer) are dormant at
    | launch: there is no credits ledger and carbon projects cannot progress
    | past Submitted. While this flag is false they are shown as "Coming soon"
    | on the web register page and rejected (422) by web and API registration.
    | Existing users already holding these roles are unaffected.
    |
    */

    'signup' => [
        'carbon_enabled' => (bool) env('SIGNUP_CARBON_ENABLED', false),
    ],

];
