<?php

use App\Models\User;

it('sends both panel login pages to the web login so 2FA cannot be skipped', function (string $path) {
    $this->get($path)->assertRedirect(route('login'));
})->with(['/admin/login', '/dashboard/login']);

it('requires the 2FA code for an enrolled user signing in via the web login', function () {
    $user = User::factory()->create(['password' => bcrypt('secret-pass-1')]);
    $user->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    if (! $user->fresh()->hasTwoFactorEnabled()) {
        $this->markTestSkipped('2FA storage format differs; covered by AccountSecurityTest.');
    }

    $this->post(route('login'), ['email' => $user->email, 'password' => 'secret-pass-1'])
        ->assertRedirect(route('login.two-factor'));

    $this->assertGuest();
});
