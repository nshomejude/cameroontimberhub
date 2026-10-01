<?php

namespace App\Filament\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;

/**
 * Both Filament panels sign in through the site's own /login, never through
 * Filament's stock form. The stock form only checks the password, so it would
 * let anyone holding an enrolled user's password skip the two-factor
 * challenge that LoginController enforces (blueprint §39). The intended URL
 * set by the panel's Authenticate middleware survives the hop, so users land
 * back on the panel page they asked for.
 */
class WebLoginRedirect extends Login
{
    public function mount(): void
    {
        $this->redirect(route('login'));
    }

    public function authenticate(): ?LoginResponse
    {
        $this->redirect(route('login'));

        return null;
    }
}
