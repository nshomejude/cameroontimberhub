<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Android APK download URL
    |--------------------------------------------------------------------------
    |
    | The buyer app (Expo / React Native, repo `cameroontimberhub-app`) is
    | written but has never been built, signed or published. There is no APK
    | and no store listing, so /mobile-app must not show a download button or a
    | store badge that goes nowhere.
    |
    | This value is the single switch for that. While it is null the page
    | renders the "in development — notify me" state with the email capture.
    | Set MOBILE_APK_URL to a real, publicly reachable .apk once one is signed
    | and hosted, and the same section turns into a download button. Nothing
    | else on the page needs to change.
    |
    | The version/size strings are cosmetic labels shown next to that button;
    | they are only rendered when an APK URL is actually set.
    |
    */

    'apk_url' => env('MOBILE_APK_URL'),

    'apk_version' => env('MOBILE_APK_VERSION'),

    'apk_size' => env('MOBILE_APK_SIZE'),

    /*
    |--------------------------------------------------------------------------
    | Store listings
    |--------------------------------------------------------------------------
    |
    | Deliberately null. When the app is actually published, set these and the
    | page renders real badges; until then it renders none, rather than linking
    | to a listing that does not exist.
    |
    */

    'play_store_url' => env('MOBILE_PLAY_STORE_URL'),

    'app_store_url' => env('MOBILE_APP_STORE_URL'),

];
