<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Creates (or promotes) a platform super_admin — the production replacement
 * for the demo seeder's well-known admin account.
 *
 *   php artisan admin:create ops@example.cm --name="Jane Doe"
 *
 * A new account gets a random 32-char password that is never shown; the
 * person sets their own via the password-reset email this command sends.
 * `--show-password` prints the random password once instead (for when mail is
 * not working yet) — rotate it immediately after first login.
 *
 * Requires RolesAndPermissionsSeeder (via ReferenceDataSeeder) to have run.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create
        {email : Email address of the platform administrator}
        {--name= : Display name (required when creating a new user)}
        {--show-password : Print a one-time random password instead of emailing a reset link}';

    protected $description = 'Create or promote a super_admin user (random password + reset link)';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error("Invalid email address [{$email}].");

            return self::FAILURE;
        }

        if (! Role::where('name', 'super_admin')->where('guard_name', 'web')->exists()) {
            $this->error('Role [super_admin] does not exist. Run `php artisan db:seed --class=ReferenceDataSeeder --force` first.');

            return self::FAILURE;
        }

        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        $password = null;

        if ($user) {
            $this->info("User [{$user->email}] exists — promoting to super_admin (password unchanged).");
        } else {
            $name = trim((string) $this->option('name'));

            if ($name === '') {
                $name = Str::before($email, '@');
            }

            $password = Str::password(32);

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            // Staff are vouched for by the operator running this command.
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->info("Created user [{$email}].");
        }

        $user->assignRole('super_admin');

        if ($password !== null && $this->option('show-password')) {
            $this->warn('One-time password (shown once — change it after first login):');
            $this->line($password);
        } elseif ($password !== null) {
            $status = Password::sendResetLink(['email' => $user->email]);

            if ($status === Password::RESET_LINK_SENT) {
                $this->info('Password-reset link emailed. Enrol 2FA after first login.');
            } else {
                $this->warn('Could not send the reset link ('.__($status).'). Re-run with --show-password, or use "Forgot password" on /login.');
            }
        }

        $this->line('Next: sign in at '.url('/login').' and enrol two-factor authentication at '
            .route('two-factor.show').' — staff cannot open /admin until 2FA is confirmed.');

        return self::SUCCESS;
    }
}
