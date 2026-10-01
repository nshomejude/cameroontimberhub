<?php

namespace App\Console\Commands;

use App\Enums\PaymentProvider;
use App\Features\DemoLoginsEnabled;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\GatewayCredentials;
use App\Support\Ops\OpsProbes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;

/**
 * Go-live gate: `php artisan launch:check` exits non-zero and lists every
 * blocking problem with this environment's production configuration
 * (debug on, log mailer, http APP_URL, demo logins, missing first admin,
 * unseeded reference data, missing storage link / signing key, ...).
 * Non-blocking concerns (cron/worker not yet observed, no payment gateway)
 * are printed as warnings.
 *
 * Run it on the server after every deploy, before opening traffic. It only
 * reads state — it never changes anything. See docs/ops/RUNBOOK.md
 * "Go-live checklist".
 */
class LaunchCheckCommand extends Command
{
    protected $signature = 'launch:check';

    protected $description = 'Verify this environment is safe to serve production traffic (non-zero exit on any failure)';

    /** Accounts the demo seeders create with well-known credentials. */
    private const DEMO_ADMIN_EMAIL = 'admin@cameroontimberhub.test';

    /** @var list<string> */
    private array $failures = [];

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        $this->checkEnvironment();
        $this->checkMail();
        $this->checkSessionAndQueue();
        $this->checkDemoAccess();
        $this->checkSeededData();
        $this->checkFilesystem();
        $this->checkRuntime();
        $this->checkPayments();
        $this->checkStaffSecurity();
        $this->checkLaunchSettings();
        $this->checkSearchExtensions();
        $this->checkQueueService();

        foreach ($this->warnings as $warning) {
            $this->components->warn($warning);
        }

        foreach ($this->failures as $failure) {
            $this->components->error($failure);
        }

        if ($this->failures !== []) {
            $this->newLine();
            $this->error(count($this->failures).' blocking problem(s) — NOT ready for launch.');

            return self::FAILURE;
        }

        $this->components->info('launch:check passed'.($this->warnings !== [] ? ' with '.count($this->warnings).' warning(s).' : '.'));

        return self::SUCCESS;
    }

    private function addFailure(string $message): void
    {
        $this->failures[] = $message;
    }

    private function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    private function checkEnvironment(): void
    {
        if (! app()->isProduction()) {
            $this->addWarning('APP_ENV is ['.app()->environment().'], not "production".');
        }

        if (config('app.debug')) {
            $this->addFailure('APP_DEBUG is true — stack traces and env values would leak to visitors.');
        }

        if (blank(config('app.key'))) {
            $this->addFailure('APP_KEY is empty — run `php artisan key:generate` once and keep it safe.');
        }

        if (! Str::startsWith((string) config('app.url'), 'https://')) {
            $this->addFailure('APP_URL ['.config('app.url').'] is not https:// — links in emails, sitemap and signed URLs would be wrong.');
        }
    }

    private function checkMail(): void
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->addFailure("MAIL_MAILER is [{$mailer}] — password resets, verification and notifications would never be delivered.");
        }

        $from = (string) config('mail.from.address');

        if ($from === '' || Str::endsWith(Str::lower($from), ['example.com', '.test', 'localhost'])) {
            $this->addFailure("MAIL_FROM_ADDRESS [{$from}] is a placeholder — set a real address on a domain with SPF/DKIM/DMARC.");
        }
    }

    private function checkSessionAndQueue(): void
    {
        if (config('session.secure') !== true) {
            $this->addFailure('SESSION_SECURE_COOKIE is not true — session cookies could be sent over plain http.');
        }

        if (config('queue.default') === 'sync' || OpsProbes::queueDriver() === 'sync') {
            $this->addFailure('QUEUE_CONNECTION is [sync] — mail and outbox events would run inline in web requests. Use database or redis with a worker.');
        }
    }

    private function checkDemoAccess(): void
    {
        if (config('demo.enabled')) {
            $this->addFailure('DEMO_LOGINS_ENABLED is true — anyone can sign in as a demo persona from /login.');
        }

        try {
            if (Feature::active(DemoLoginsEnabled::class)) {
                $this->addFailure('The DemoLoginsEnabled Pennant flag is active in the `features` table — run `Feature::deactivate(App\Features\DemoLoginsEnabled::class)`.');
            }
        } catch (\Throwable $e) {
            $this->addWarning('Could not read the DemoLoginsEnabled flag: '.$e->getMessage());
        }

        try {
            if (User::whereRaw('lower(email) = ?', [self::DEMO_ADMIN_EMAIL])->exists()) {
                $this->addFailure('The demo super admin ['.self::DEMO_ADMIN_EMAIL.'] (password "password") exists — delete it.');
            }
        } catch (\Throwable) {
            // Database failures are reported by checkSeededData().
        }
    }

    private function checkSeededData(): void
    {
        try {
            if (! Role::where('name', 'super_admin')->exists()) {
                $this->addFailure('Roles/permissions are not seeded — run `php artisan db:seed --class=ReferenceDataSeeder --force`.');
            } elseif (! User::role('super_admin')->exists()) {
                $this->addFailure('No super_admin user exists — run `php artisan admin:create you@domain`.');
            } elseif (User::role('super_admin')->count() < 2) {
                $this->addWarning('Only one super_admin — two-person controls (payment credentials, approvals) need a second admin.');
            }

            if (Plan::count() === 0) {
                $this->addFailure('No subscription plans are seeded — run `php artisan db:seed --class=ReferenceDataSeeder --force`.');
            }
        } catch (\Throwable $e) {
            $this->addFailure('Database not reachable / not migrated: '.$e->getMessage());
        }
    }

    private function checkFilesystem(): void
    {
        foreach ((array) config('filesystems.links', []) as $link => $target) {
            if (! file_exists($link)) {
                $this->addFailure("Public storage link [{$link}] is missing — run `php artisan storage:link`.");
            }
        }

        $keyPath = (string) config('certificates.signing_key_path');

        if ($keyPath === '' || ! is_readable($keyPath)) {
            $this->addFailure("Certificate signing key [{$keyPath}] is missing — run `php artisan certificates:generate-signing-key` once (and back it up).");
        }
    }

    private function checkRuntime(): void
    {
        try {
            if (! OpsProbes::schedulerIsFresh()) {
                $last = OpsProbes::lastSchedulerHeartbeat();
                $this->addWarning('Scheduler heartbeat '.($last ? 'is stale (last '.$last->diffForHumans().')' : 'never recorded').' — install the cron entry (deploy/cron) and wait a minute.');
            }
        } catch (\Throwable $e) {
            $this->addWarning('Could not read the scheduler heartbeat: '.$e->getMessage());
        }

        try {
            $age = OpsProbes::oldestPendingAgeSeconds();

            if ($age !== null && $age > 300) {
                $this->addWarning('Oldest pending queue job is '.intdiv($age, 60).' min old — is the queue worker (timberhub-queue.service) running?');
            }
        } catch (\Throwable $e) {
            $this->addWarning('Could not inspect the queue: '.$e->getMessage());
        }
    }

    private function checkPayments(): void
    {
        $configured = [];

        foreach (PaymentProvider::cases() as $provider) {
            try {
                if (GatewayCredentials::isConfigured($provider)) {
                    $configured[] = $provider->value;
                }
            } catch (\Throwable) {
                // Treated as not configured.
            }
        }

        if ($configured === []) {
            $this->addWarning('No payment gateway is configured — paid plans cannot be purchased (manual invoicing only).');
        } else {
            $this->line('  Payment gateways configured: '.implode(', ', $configured).' (mobile-money callbacks are verified against the provider status API).');
        }
    }

    private function checkStaffSecurity(): void
    {
        if (app()->isProduction() && config('auth.require_staff_2fa') !== true) {
            $this->addFailure('STAFF_REQUIRE_2FA is not true — staff could reach /admin without two-factor authentication.');
        }

        try {
            $admins = User::role(['super_admin', 'admin'])
                ->where(fn ($q) => $q->whereNull('two_factor_secret')->orWhereNull('two_factor_confirmed_at'))
                ->get(['id', 'email']);

            foreach ($admins as $admin) {
                $this->addWarning("Admin [{$admin->email}] has not confirmed two-factor authentication — enrol TOTP before launch.");
            }
        } catch (\Throwable) {
            // Missing roles / database failures are reported by checkSeededData().
        }
    }

    private function checkLaunchSettings(): void
    {
        $proxies = config('app.trusted_proxies');
        $this->line('  TRUSTED_PROXIES: ['.(is_array($proxies) ? implode(',', $proxies) : (string) $proxies).']');

        if (blank($proxies)) {
            $this->addWarning('TRUSTED_PROXIES is blank — behind nginx/Cloudflare the app would see http and the proxy IP (no HSTS, wrong client IPs).');
        }

        if (config('timber.signup.carbon_enabled')) {
            $this->addWarning('SIGNUP_CARBON_ENABLED is true — carbon accounts can register but there is no admin review for carbon projects yet.');
        }

        $inbox = (string) config('contact.inbox');

        if ($inbox === '' || Str::endsWith(Str::lower($inbox), ['.test', 'example.com'])) {
            $this->addWarning("CONTACT_INBOX [{$inbox}] is blank or a placeholder — contact-form messages would not reach anyone.");
        }

        $ops = (string) config('mail.ops_address');

        // config/mail.php falls back to MAIL_FROM_ADDRESS when MAIL_OPS_ADDRESS is unset
        // (env() cannot be read here once config is cached).
        if ($ops === '' || Str::endsWith(Str::lower($ops), ['.test', 'example.com'])
            || Str::lower($ops) === Str::lower((string) config('mail.from.address'))) {
            $this->addWarning("MAIL_OPS_ADDRESS is not set (currently [{$ops}]) — the daily error digest has no dedicated recipient.");
        }

        $this->line('  API_REQUIRE_TERMS_ACCEPTED: '.(config('auth.api_require_terms_accepted') ? 'true' : 'false').' (set true once every live app build sends terms_accepted).');
    }

    private function checkSearchExtensions(): void
    {
        try {
            if (DB::connection()->getDriverName() !== 'pgsql') {
                return;
            }

            $extensions = DB::table('pg_extension')->whereIn('extname', ['unaccent', 'pg_trgm'])->pluck('extname')->all();

            foreach (['unaccent', 'pg_trgm'] as $extension) {
                if (! in_array($extension, $extensions, true)) {
                    $this->addWarning("PostgreSQL extension [{$extension}] is missing — run `CREATE EXTENSION IF NOT EXISTS {$extension};` as a superuser, then re-run the 2026_10_01_140000 search-index migration.");
                }
            }

            if (! DB::table('pg_proc')->where('proname', 'ct_unaccent')->exists()) {
                $this->addWarning('Function ct_unaccent() is missing — instant search falls back to accent-sensitive matching without trigram indexes.');
            }
        } catch (\Throwable $e) {
            $this->addWarning('Could not inspect PostgreSQL search extensions: '.$e->getMessage());
        }
    }

    /** Best effort: only when systemctl exists on this host; never fails the gate. */
    private function checkQueueService(): void
    {
        try {
            if (! Process::run(['sh', '-c', 'command -v systemctl'])->successful()) {
                return;
            }

            $result = Process::timeout(10)->run(['systemctl', 'is-active', 'timberhub-queue']);

            if (! $result->successful()) {
                $state = trim($result->output()) ?: 'unknown';
                $this->addWarning("systemd unit timberhub-queue is [{$state}], not active — `systemctl enable --now timberhub-queue`.");
            }
        } catch (\Throwable) {
            // systemctl unavailable or not permitted here: nothing to report.
        }
    }
}
