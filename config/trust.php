<?php

return [

    // Minimum seconds a form must be on screen before submit (bot guard).
    'min_form_seconds' => 3,

    // spam_score >= this auto-flags is_spam = true.
    'autospam_threshold' => 70,

    'turnstile' => [
        'enabled' => env('TURNSTILE_ENABLED', false),
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    'free_email_domains' => [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com',
        'protonmail.com', 'mail.com', 'gmx.com', 'yandex.com', 'qq.com', '163.com',
    ],

    'disposable_email_domains' => [
        'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'trashmail.com',
    ],

    // Additive spam-score weights (0-100 band).
    'weights' => [
        'free_email_high_volume' => 25,
        'burst_ip' => 30,
        'duplicate_recent' => 30,
        'disposable_email' => 40,
        'link_in_message' => 15,
        'oversized_quantity' => 20,
    ],

    // m3/ton/pcs/container thresholds above which a line item looks oversized.
    'oversized_quantity' => 100000,
    'free_email_volume_threshold' => 500,

    'retention' => [
        'unverified_days' => 7,
        'events_days' => 180,
    ],
];
