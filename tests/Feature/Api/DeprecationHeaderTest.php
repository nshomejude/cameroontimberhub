<?php

use Illuminate\Support\Facades\Route;

/**
 * Covers the reusable `deprecated` middleware (App\Http\Middleware\
 * AnnounceDeprecation) against throwaway routes. No real /api/v1 endpoint is
 * deprecated today — this proves the mechanism for GAPS.md gap 5.
 */
beforeEach(function () {
    Route::middleware('deprecated:2027-01-01,2027-07-01,https://www.cameroontimberhub.com/api/v2/thing,Use v2')
        ->get('/__test/deprecated/full', fn () => response()->json(['ok' => true]));

    Route::middleware('deprecated:2027-01-01,2027-07-01')
        ->get('/__test/deprecated/no-successor', fn () => response()->json(['ok' => true]));

    Route::middleware('deprecated:not-a-date')
        ->get('/__test/deprecated/bad-date', fn () => response()->json(['ok' => true]));

    Route::middleware('deprecated')
        ->get('/__test/deprecated/bare', fn () => response()->json(['ok' => true]));

    Route::get('/__test/deprecated/none', fn () => response()->json(['ok' => true]));
});

it('emits a correctly formatted Deprecation and Sunset HTTP-date', function () {
    $res = $this->getJson('/__test/deprecated/full');

    $res->assertOk();
    expect($res->headers->get('Deprecation'))->toBe('Fri, 01 Jan 2027 00:00:00 GMT');
    expect($res->headers->get('Sunset'))->toBe('Thu, 01 Jul 2027 00:00:00 GMT');
});

it('emits a Link successor-version header when a successor URL is passed', function () {
    $res = $this->getJson('/__test/deprecated/full');

    expect($res->headers->get('Link'))
        ->toBe('<https://www.cameroontimberhub.com/api/v2/thing>; rel="successor-version"');
    expect($res->headers->get('Warning'))->toBe('299 - "Use v2"');
});

it('omits the Link header when no successor URL is passed', function () {
    $res = $this->getJson('/__test/deprecated/no-successor');

    $res->assertOk();
    expect($res->headers->get('Deprecation'))->toBe('Fri, 01 Jan 2027 00:00:00 GMT');
    expect($res->headers->has('Link'))->toBeFalse();
    expect($res->headers->has('Warning'))->toBeFalse();
});

it('degrades to Deprecation: true on a malformed date without 500ing', function () {
    $res = $this->getJson('/__test/deprecated/bad-date');

    $res->assertOk();
    expect($res->headers->get('Deprecation'))->toBe('true');
    expect($res->headers->has('Sunset'))->toBeFalse();
});

it('degrades to Deprecation: true when no params are given', function () {
    $res = $this->getJson('/__test/deprecated/bare');

    $res->assertOk();
    expect($res->headers->get('Deprecation'))->toBe('true');
    expect($res->headers->has('Sunset'))->toBeFalse();
    expect($res->headers->has('Link'))->toBeFalse();
});

it('adds none of the headers to a route without the middleware', function () {
    $res = $this->getJson('/__test/deprecated/none');

    $res->assertOk();
    expect($res->headers->has('Deprecation'))->toBeFalse();
    expect($res->headers->has('Sunset'))->toBeFalse();
    expect($res->headers->has('Link'))->toBeFalse();
});
