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
    | BEFORE enabling SIGNUP_CARBON_ENABLED: build an admin (Filament)
    | CarbonProject review resource first — today nothing can move a carbon
    | project past Submitted, so new developers would be stuck. See RUNBOOK.
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

    /*
    |--------------------------------------------------------------------------
    | Marketplace commission (PRICING_SPEC §15)
    |--------------------------------------------------------------------------
    |
    | Rates and caps live in `commission_rules` (/admin → Commission rules),
    | never here. This block only holds the plumbing around them:
    |
    | usd_to_xaf / usd_to_eur / usd_to_gbp / usd_to_cny: units of that
    | currency per 1 USD, used ONLY to express a rule's fixed cap (e.g. the
    | §15 "$5,000" cap, stored with cap_currency = USD) in the order's own
    | currency. EUR defaults to the fixed XAF/EUR peg (655.957) applied to
    | usd_to_xaf. When no rate is configured for a pair, the fixed-amount cap
    | is skipped for that order (the percentage cap still applies).
    |
    | Collection (owner decision 2026-10-01 — manual MoMo / bank deposit
    | against monthly statements, see RUNBOOK → Commission collection):
    | statement_due_days: days after issue a statement falls due.
    | reminder_days_before: the "due soon" reminder lead time.
    | block_on_overdue_days: ON by default at 15 (owner decision 2026-10-01):
    | a company with a statement still unpaid more than N days past its due
    | date cannot submit new quotes (409 `commission_overdue`) until it pays.
    | Set COMMISSION_BLOCK_ON_OVERDUE_DAYS to another number to change it, or
    | to `off` to only remind.
    |
    */

    'commission' => [
        'usd_to_xaf' => (float) env('COMMISSION_USD_TO_XAF', 600),
        'usd_to_eur' => filled(env('COMMISSION_USD_TO_EUR'))
            ? (float) env('COMMISSION_USD_TO_EUR')
            : round((float) env('COMMISSION_USD_TO_XAF', 600) / 655.957, 6),
        'usd_to_gbp' => filled(env('COMMISSION_USD_TO_GBP')) ? (float) env('COMMISSION_USD_TO_GBP') : null,
        'usd_to_cny' => filled(env('COMMISSION_USD_TO_CNY')) ? (float) env('COMMISSION_USD_TO_CNY') : null,
        'statement_due_days' => (int) env('COMMISSION_STATEMENT_DUE_DAYS', 15),
        'reminder_days_before' => (int) env('COMMISSION_REMINDER_DAYS_BEFORE', 3),
        'block_on_overdue_days' => in_array(strtolower(trim((string) env('COMMISSION_BLOCK_ON_OVERDUE_DAYS', '15'))), ['off', 'false', 'none'], true)
            ? null
            : (filled(env('COMMISSION_BLOCK_ON_OVERDUE_DAYS')) ? (int) env('COMMISSION_BLOCK_ON_OVERDUE_DAYS') : 15),
    ],

];
