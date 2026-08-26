<?php

/*
|--------------------------------------------------------------------------
| Public contact details
|--------------------------------------------------------------------------
|
| Single source of truth for the address, phone numbers, mailboxes, office
| hours and social profiles rendered on /contact (and emitted as ContactPage
| JSON-LD). Everything here is env-overridable so an operator can correct a
| phone number or a mailbox without a code change, and every key can be
| overridden per-page from the CMS (`pages.data.contact`) by an admin with
| `pages.manage` — see App\Http\Controllers\Public\ContactController.
|
| `social` entries are emitted into schema.org `sameAs`; leave a URL empty and
| the profile is dropped from both the page and the structured data, so we
| never claim a social presence that does not exist.
|
*/

return [

    'organisation' => env('CONTACT_ORG_NAME', 'Cameroon Timber Hub'),

    'address' => [
        'label' => env('CONTACT_ADDRESS_LABEL', 'Head Quarters'),
        'lines' => array_values(array_filter([
            env('CONTACT_ADDRESS_LINE1', 'Bonanjo, Akwa, Douala'),
            env('CONTACT_ADDRESS_LINE2', 'Douala, Littoral Region, Cameroon'),
            env('CONTACT_ADDRESS_LINE3', 'P.O. Box 12545 Douala'),
        ])),
        'locality' => env('CONTACT_ADDRESS_LOCALITY', 'Douala'),
        'region' => env('CONTACT_ADDRESS_REGION', 'Littoral'),
        'postal_code' => env('CONTACT_ADDRESS_POSTAL_CODE', ''),
        'country' => env('CONTACT_ADDRESS_COUNTRY', 'CM'),
    ],

    // Approximate coordinates of the Bonanjo/Akwa business district. Used only
    // to build an outbound maps link — no third-party map is embedded.
    'geo' => [
        'latitude' => (float) env('CONTACT_GEO_LAT', 4.0511),
        'longitude' => (float) env('CONTACT_GEO_LNG', 9.7679),
    ],

    'phones' => array_values(array_filter([
        env('CONTACT_PHONE_1', '+237 6 95 84 27 26'),
        env('CONTACT_PHONE_2', '+237 6 70 11 22 33'),
    ])),

    'emails' => array_values(array_filter([
        env('CONTACT_EMAIL_GENERAL', 'info@cameroontimberhub.africa'),
        env('CONTACT_EMAIL_PARTNERSHIPS', 'partnerships@cameroontimberhub.africa'),
    ])),

    'website' => env('CONTACT_WEBSITE', 'www.cameroontimberhub.africa'),

    'hours' => [
        ['days' => 'Monday – Friday', 'time' => '08:00 AM – 05:00 PM (GMT +1)'],
        ['days' => 'Saturday', 'time' => '09:00 AM – 01:00 PM (GMT +1)'],
    ],

    'hours_note' => env('CONTACT_HOURS_NOTE', 'Closed on Sundays and public holidays.'),

    'social' => array_filter([
        'linkedin' => env('CONTACT_SOCIAL_LINKEDIN', ''),
        'facebook' => env('CONTACT_SOCIAL_FACEBOOK', ''),
        'instagram' => env('CONTACT_SOCIAL_INSTAGRAM', ''),
        'youtube' => env('CONTACT_SOCIAL_YOUTUBE', ''),
    ]),

];
