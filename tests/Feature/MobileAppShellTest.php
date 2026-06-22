<?php

it('wraps public pages in the native app shell with PWA hooks', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('tab-bar', false)              // bottom tab bar
        ->assertSee('id="app-main"', false)        // routed page container (transitions target)
        ->assertSee('manifest.webmanifest', false) // installable PWA manifest
        ->assertSee('apple-mobile-web-app-capable', false) // iOS standalone
        ->assertSee('Exporters');                  // a tab label
});

it('shows the app shell on a deep screen with a back affordance', function () {
    \App\Models\Species::factory()->create(['common_name' => 'Shellwood']);

    $this->get(route('species.show', 'shellwood'))
        ->assertOk()
        ->assertSee('history.back()', false) // top-bar back button on detail screens
        ->assertSee('tab-bar', false);
});
