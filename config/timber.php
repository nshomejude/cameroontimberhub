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

    /*
    |--------------------------------------------------------------------------
    | RFQ "open request" distribution
    |--------------------------------------------------------------------------
    |
    | auto_approve_low_risk: a buyer-verified RFQ whose RfqRiskService score is
    | 0 (no heuristic flags) is approved without waiting for staff.
    | auto_route_on_approval: on approval (staff or auto) the RFQ is routed to
    | every matching, leads-entitled supplier (RfqMatchingService), at most
    | auto_route_max of them. Staff can still route more by hand, and
    | suppliers can pick open requests off their "Buyer requests" board.
    |
    */

    'rfq' => [
        'auto_approve_low_risk' => (bool) env('RFQ_AUTO_APPROVE_LOW_RISK', true),
        'auto_route_on_approval' => (bool) env('RFQ_AUTO_ROUTE_ON_APPROVAL', true),
        'auto_route_max' => (int) env('RFQ_AUTO_ROUTE_MAX', 30),
    ],

];
