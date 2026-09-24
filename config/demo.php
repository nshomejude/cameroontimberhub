<?php

return [

    /*
    |--------------------------------------------------------------------------
    | One-click demo logins
    |--------------------------------------------------------------------------
    |
    | When enabled, the public login page renders three "sign in as a demo
    | account" buttons and POST /demo-login/{persona} signs the visitor in as
    | the matching seeded account.
    |
    | SECURITY: the `admin` persona is a real super_admin with FULL access to
    | the /admin panel — a deliberate, owner-accepted exposure for the public
    | demo, not an oversight. Anyone who can reach the login page while this
    | flag is true can administer the platform. `DEMO_LOGINS_ENABLED=false`
    | (the default) is the kill switch: it hides the buttons AND makes the
    | route itself refuse, so turning it off is sufficient to close the hole.
    |
    */

    'enabled' => (bool) env('DEMO_LOGINS_ENABLED', false),

    /*
     * The allow-list. A request only ever supplies a key from this array —
     * never an email, an id, or anything else that could resolve to an
     * arbitrary account — so a demo login can never be steered at a real user.
     * The accounts themselves are created by Database\Seeders\DemoLoginSeeder.
     */
    'personas' => [
        'buyer' => [
            'email' => 'demo.buyer@cameroontimberhub.com',
            'name' => 'Demo Buyer',
            'label' => 'Demo Buyer',
            'description' => 'Requests, quotes and orders',
            'icon' => 'shopping-bag',
        ],
        'supplier' => [
            'email' => 'demo.supplier@cameroontimberhub.com',
            'name' => 'Demo Supplier',
            'label' => 'Demo Supplier',
            'description' => 'A verified exporter’s panel',
            'icon' => 'building-office-2',
        ],
        'admin' => [
            'email' => 'demo.admin@cameroontimberhub.com',
            'name' => 'Demo Admin',
            'label' => 'Demo Admin',
            'description' => 'The staff moderation panel',
            'icon' => 'shield-check',
        ],
        'logistics' => [
            'email' => 'demo.logistics@cameroontimberhub.com',
            'name' => 'Demo Logistics',
            'label' => 'Demo Logistics',
            'description' => 'A fleet operator\'s vehicles and drivers',
            'icon' => 'truck',
        ],
        'pending_supplier' => [
            'email' => 'demo.pending_supplier@cameroontimberhub.com',
            'name' => 'Demo Pending Supplier',
            'label' => 'Demo Pending Supplier',
            'description' => 'A supplier account awaiting verification',
            'icon' => 'clock',
        ],
        'processor' => [
            'email' => 'demo.processor@cameroontimberhub.com',
            'name' => 'Demo Processor',
            'label' => 'Demo Processor',
            'description' => 'A sawmill with capacity and a real transformation',
            'icon' => 'cog-6-tooth',
        ],
        'manufacturer' => [
            'email' => 'demo.manufacturer@cameroontimberhub.com',
            'name' => 'Demo Manufacturer',
            'label' => 'Demo Manufacturer',
            'description' => 'A finished-goods manufacturer with capacity and products',
            'icon' => 'wrench-screwdriver',
        ],
        'artisan' => [
            'email' => 'demo.artisan@cameroontimberhub.com',
            'name' => 'Demo Artisan',
            'label' => 'Demo Artisan',
            'description' => 'A small-batch artisan with marketplace listings',
            'icon' => 'paint-brush',
        ],
        'retailer' => [
            'email' => 'demo.retailer@cameroontimberhub.com',
            'name' => 'Demo Retailer',
            'label' => 'Demo Retailer',
            'description' => 'A timber yard with local marketplace stock',
            'icon' => 'building-storefront',
        ],
        'carbon_developer' => [
            'email' => 'demo.carbon_developer@cameroontimberhub.com',
            'name' => 'Demo Carbon Developer',
            'label' => 'Demo Carbon Developer',
            'description' => 'A carbon project developer with a registered project',
            'icon' => 'globe-alt',
        ],
    ],

];
