<?php

namespace App\Features;

use Illuminate\Support\Facades\Config;

/**
 * Whether the one-click demo-login route and buttons are available.
 *
 * Backed by Pennant's `database` driver, so once resolved the value is
 * persisted in the `features` table and can be flipped at runtime with
 * `Feature::activate(self::class)` / `Feature::deactivate(self::class)`
 * (e.g. from `php artisan tinker`) without a deploy. `resolve()` only
 * supplies the *initial* value the first time the flag is checked for a
 * given scope — it seeds the stored row from `DEMO_LOGINS_ENABLED` so the
 * env var remains the deploy-time default, but an explicit
 * activate/deactivate call always wins after that.
 *
 * Unscoped (global) on purpose: demo-login availability is a platform-wide
 * switch, not a per-user preference, so this is always checked as
 * `Feature::active(self::class)` — never `Feature::for($user)->active(...)`.
 */
class DemoLoginsEnabled
{
    public function resolve(mixed $scope): bool
    {
        return (bool) Config::get('demo.enabled', false);
    }
}
