<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_test/client-ip', fn (Request $request) => response()->json([
        'ip' => $request->ip(),
        'secure' => $request->secure(),
    ]));
});

it('honours X-Forwarded-For / Proto from a trusted loopback proxy by default', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
        ->getJson('/_test/client-ip')
        ->assertJson(['ip' => '203.0.113.9', 'secure' => true]);
});

it('ignores forwarded headers from an untrusted peer', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
        ->getJson('/_test/client-ip')
        ->assertJson(['ip' => '198.51.100.7', 'secure' => false]);
});

it('trusts any peer when TRUSTED_PROXIES is *', function () {
    config(['app.trusted_proxies' => '*']);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->getJson('/_test/client-ip')
        ->assertJson(['ip' => '203.0.113.9']);
});

it('accepts a CIDR list', function () {
    config(['app.trusted_proxies' => '10.0.0.0/8, 198.51.100.0/24']);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->getJson('/_test/client-ip')
        ->assertJson(['ip' => '203.0.113.9']);
});
