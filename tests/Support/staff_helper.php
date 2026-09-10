<?php

/*
 * `staff()` — a User with a single platform role assigned.
 *
 * Used by more than one feature file (AdminPanelTest, DocumentPolicyTest, ...).
 * Lives here — loaded via composer autoload-dev.files — because
 * `php artisan test --parallel` shards test files across workers: a helper
 * defined at the top of one file is invisible to another file in a different
 * worker. An autoloaded file is present in every worker.
 */

use App\Models\User;

if (! function_exists('staff')) {
    function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
