<?php

namespace App\Listeners;

use App\Actions\Auth\AdoptGuestRfqs;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

/**
 * Once a user proves they own their email address, adopt the guest RFQs that
 * address submitted earlier (see AdoptGuestRfqs for why this waits).
 */
class AdoptGuestRfqsOnEmailVerified
{
    public function __construct(private readonly AdoptGuestRfqs $adopt) {}

    public function handle(Verified $event): void
    {
        if ($event->user instanceof User) {
            ($this->adopt)($event->user);
        }
    }
}
