<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Order;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Species;
use App\Models\User;
use App\Services\LeadFlowService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * The three accounts behind the one-click demo logins on /login.
 *
 * Nothing here invents a parallel universe of fixtures: the personas are
 * *attached* to the data the existing demo seeders already produce. The buyer
 * adopts the seeded RFQs (and the orders awarded on them) exactly the way
 * RegisterController adopts an account-free RFQ; the supplier joins the
 * flagship demo company on the `company_user` pivot; the admin gets the real
 * `super_admin` role.
 *
 * Passwords are strong and random, are never printed, and are irrelevant to
 * the demo buttons — DemoLoginController resolves a persona to an account and
 * calls Auth::login(), so no password is ever transmitted or displayed.
 *
 * Idempotent, and safe standalone:
 *
 *     php artisan db:seed --class=DemoLoginSeeder
 *
 * Re-running it produces exactly one user per persona, re-uses the same
 * membership row, and re-adopts nothing (the RFQs already have a user_id).
 * It bootstraps only the prerequisites that are actually missing, so a second
 * run is cheap. It is deliberately NOT wired into DatabaseSeeder, which is
 * known not to be re-runnable (a pre-existing users_email_unique collision on
 * admin@cameroontimberhub.test), and adding a step there would make that worse
 * rather than better.
 */
class DemoLoginSeeder extends Seeder
{
    /** The flagship demo supplier the demo supplier account owns. */
    private const SUPPLIER_COMPANY = 'Kuété Timber Group Sarl';

    public function run(): void
    {
        $this->ensurePrerequisites();

        $buyer = $this->persona('buyer');
        $supplier = $this->persona('supplier');
        $admin = $this->persona('admin');

        $this->wireBuyer($buyer);
        $this->wireSupplier($supplier);
        $this->wireAdmin($admin);

        $this->command?->info('Demo personas ready: '.collect(config('demo.personas'))->pluck('email')->join(', '));
    }

    /* ------------------------------------------------------------- accounts */

    /**
     * One user per persona, keyed on the configured email so a re-run updates
     * rather than duplicates. The password is random on creation and left
     * alone afterwards — rotating it on every seed would invalidate nothing
     * useful and would churn the hash for no reason.
     */
    private function persona(string $key): User
    {
        $config = config("demo.personas.{$key}");

        if (! is_array($config) || ! isset($config['email'])) {
            throw new \RuntimeException("config('demo.personas.{$key}') is missing or malformed.");
        }

        return User::firstOrCreate(
            ['email' => $config['email']],
            [
                'name' => $config['name'] ?? Str::headline($key),
                // Never displayed, never logged, never needed by the buttons.
                'password' => Str::password(48),
                'email_verified_at' => now(),
            ],
        );
    }

    /* ---------------------------------------------------------------- buyer */

    /**
     * The buyer stays a plain account: no company membership, no staff role.
     * It adopts the seeded RFQs so /account has real requests, quotes, orders
     * and receipts behind every tile.
     */
    private function wireBuyer(User $buyer): void
    {
        DB::transaction(function () use ($buyer): void {
            $rfqIds = Rfq::query()
                ->whereNull('user_id')
                ->where('source', 'seeder')
                ->pluck('id');

            if ($rfqIds->isNotEmpty()) {
                Rfq::whereIn('id', $rfqIds)->update([
                    'user_id' => $buyer->getKey(),
                    'buyer_name' => $buyer->name,
                    'buyer_email' => $buyer->email,
                ]);
            }

            // Orders carry their own denormalised buyer id (OrderService copies
            // it off the RFQ at award time), so an adoption after the award has
            // to bring them along.
            Order::query()
                ->whereNull('user_id')
                ->whereIn('rfq_id', Rfq::query()->where('user_id', $buyer->getKey())->select('id'))
                ->update(['user_id' => $buyer->getKey()]);
        });
    }

    /* ------------------------------------------------------------- supplier */

    /**
     * The supplier owns a real, verified demo company via `company_user`, so
     * the Filament exporter panel at /dashboard is genuinely scoped to data
     * that exists. Leads are materialised from the routings the demo seeders
     * already created, through the same LeadFlowService the live intake uses.
     */
    private function wireSupplier(User $supplier): void
    {
        $company = Company::query()->where('legal_name', self::SUPPLIER_COMPANY)->first()
            ?? Company::query()->publiclyVisible()->first()
            ?? Company::query()->first();

        if ($company === null) {
            $this->command?->warn('DemoLoginSeeder: no demo company found — run DemoCompanySeeder first.');

            return;
        }

        // `company_user_primary_idx` is a unique index on (company_id) WHERE
        // is_primary — so a company can only ever have one primary member. The
        // flagship demo company already has one, and claiming it here would
        // both break re-runs and demote the real owner. Take primary only when
        // the seat is genuinely free (and never steal it from someone else).
        $primaryTaken = DB::table('company_user')
            ->where('company_id', $company->getKey())
            ->where('is_primary', true)
            ->where('user_id', '!=', $supplier->getKey())
            ->exists();

        $supplier->companies()->syncWithoutDetaching([
            $company->getKey() => ['role' => 'owner', 'is_primary' => ! $primaryTaken],
        ]);

        // The company that actually won the demo order, when it is not the
        // flagship one, so the panel's Orders tab is never empty.
        $orderCompanyId = Order::query()->whereNotNull('company_id')->value('company_id');

        if ($orderCompanyId !== null && (int) $orderCompanyId !== (int) $company->getKey()) {
            $supplier->companies()->syncWithoutDetaching([
                $orderCompanyId => ['role' => 'owner', 'is_primary' => false],
            ]);
        }

        $leads = app(LeadFlowService::class);
        $companyIds = $supplier->companies()->pluck('companies.id');

        RfqCompany::query()
            ->whereIn('company_id', $companyIds)
            ->with('rfq')
            ->get()
            ->each(function (RfqCompany $routing) use ($leads): void {
                if ($routing->rfq !== null) {
                    $leads->createFromRouting($routing);
                }
            });
    }

    /* ---------------------------------------------------------------- admin */

    /**
     * SECURITY: this is a real super_admin with FULL access to /admin. The
     * exposure is deliberate and owner-accepted for the demo environment;
     * DEMO_LOGINS_ENABLED=false (the default) disables the buttons and the
     * route, which is what actually closes it. Seeding the account on its own
     * grants nobody anything — there is no way to sign in as it without the
     * flag on, because the password is random and never surfaced.
     */
    private function wireAdmin(User $admin): void
    {
        $role = Role::where('name', 'super_admin')->where('guard_name', 'web')->first()
            ?? Role::where('name', 'admin')->where('guard_name', 'web')->first();

        if ($role === null) {
            $this->command?->warn('DemoLoginSeeder: no super_admin/admin role — run RolesAndPermissionsSeeder first.');

            return;
        }

        if (! $admin->hasRole($role)) {
            $admin->assignRole($role);
        }
    }

    /* -------------------------------------------------------- prerequisites */

    /**
     * Bootstrap only what is actually missing, so the seeder works standalone
     * on an empty database and is nearly free on a re-run.
     */
    private function ensurePrerequisites(): void
    {
        if (Role::query()->doesntExist()) {
            $this->call(RolesAndPermissionsSeeder::class);
        }

        if (Species::query()->doesntExist()) {
            $this->call(SpeciesSeeder::class);
        }

        if (Company::query()->doesntExist()) {
            $this->call([DemoCompanySeeder::class, ProductSeeder::class]);
        }

        if (Rfq::query()->doesntExist()) {
            $this->call([QuoteSeeder::class, OrderSeeder::class]);
        }
    }
}
