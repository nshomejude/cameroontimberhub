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

];
