<?php

namespace Database\Seeders;

use App\Enums\CompanyStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\OrganisationType;
use App\Models\Company;
use App\Models\Document;
use App\Models\Driver;
use App\Models\Message;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Species;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\MessageReceivedNotification;
use App\Notifications\OrderStatusChangedNotification;
use App\Notifications\PaymentConfirmedNotification;
use App\Notifications\QuoteAcceptedNotification;
use App\Notifications\QuoteReceivedNotification;
use App\Notifications\RfqRoutedToExporter;
use App\Services\LeadFlowService;
use App\Services\OrderService;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\DatabaseNotification;
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

    /** The dedicated demo company the `logistics` persona owns. */
    private const LOGISTICS_COMPANY = 'Bantu Freight Logistics Sarl';

    /** The dedicated demo company the `pending_supplier` persona owns. */
    private const PENDING_SUPPLIER_COMPANY = 'Nkolbisson Timber Traders Sarl';

    public function run(): void
    {
        $this->ensurePrerequisites();

        $buyer = $this->persona('buyer');
        $supplier = $this->persona('supplier');
        $admin = $this->persona('admin');
        $logistics = $this->persona('logistics');
        $pendingSupplier = $this->persona('pending_supplier');

        $this->wireBuyer($buyer);
        $this->wireSupplier($supplier);
        $this->wireAdmin($admin);
        $this->wireLogistics($logistics);
        $this->wirePendingSupplier($pendingSupplier);

        $this->seedNotifications($buyer, $supplier, $logistics);

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

        // `DemoCompanySeeder`'s companies predate the `type` column and may
        // never have had one set — without it, the API's role/organisation
        // gating can't tell a seller from a haulier (auth/me's
        // company.type comes back null). Mirror the logistics persona's
        // force-fill below: this is a demo fixture, not live data, so
        // stamping a sensible default here is safe.
        if ($company->type === null) {
            $company->forceFill(['type' => OrganisationType::Supplier])->save();
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

        $this->seedFleet($company);
    }

    /**
     * A couple of vehicles and drivers so the exporter panel's Fleet
     * resources are not empty in the demo. One document is deliberately near
     * expiry so the derived "Expiring soon" compliance state is visible.
     * Idempotent on the (company_id, registration_number/license_number)
     * unique indexes.
     */
    private function seedFleet(Company $company): void
    {
        $truck = Vehicle::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'registration_number' => 'CE-4821-A'],
            ['type' => 'truck', 'capacity_tonnes' => 18.00, 'is_active' => true],
        );

        Vehicle::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'registration_number' => 'LT-1907-B'],
            ['type' => 'trailer', 'capacity_tonnes' => 32.00, 'is_active' => true],
        );

        $driver = Driver::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'license_number' => 'CMR-DL-448120'],
            ['name' => 'Emmanuel Fotso', 'phone' => '+237677123045', 'is_active' => true],
        );

        Driver::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'license_number' => 'CMR-DL-771903'],
            ['name' => 'Bernadette Ayissi', 'phone' => '+237699884210', 'is_active' => true],
        );

        if ($truck->documents()->doesntExist()) {
            $truck->documents()->create([
                'type' => 'insurance_certificate',
                'original_filename' => 'ce-4821-a-insurance.pdf',
                'disk' => 'documents',
                'storage_path' => 'demo/fleet/ce-4821-a-insurance.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 1024,
                'issuer' => 'Chanas Assurances',
                'issued_at' => now()->subMonths(11),
                'expires_at' => now()->addDays(21),
            ]);
        }

        if ($driver->documents()->doesntExist()) {
            $driver->documents()->create([
                'type' => 'drivers_license',
                'original_filename' => 'cmr-dl-448120.pdf',
                'disk' => 'documents',
                'storage_path' => 'demo/fleet/cmr-dl-448120.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 1024,
                'issuer' => 'MINTRANSPORTS',
                'issued_at' => now()->subYears(3),
                'expires_at' => now()->addYears(2),
            ]);
        }
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

    /* ----------------------------------------------------------- logistics */

    /**
     * A dedicated `OrganisationType::Logistics` company, owned by this
     * persona, with its own fleet — so `GET /supplier/fleet/vehicles`
     * returns real rows for a caller whose *only* company is a logistics
     * one (FleetApiScope::eligible() checks `company.type`, not a role).
     * Idempotent on the company slug/legal_name and the `company_user`
     * pivot.
     */
    private function wireLogistics(User $logistics): void
    {
        $company = Company::firstOrCreate(
            ['slug' => Str::slug(self::LOGISTICS_COMPANY)],
            [
                'legal_name' => self::LOGISTICS_COMPANY,
                'trade_name' => 'Bantu Freight',
                'type' => OrganisationType::Logistics,
                'status' => CompanyStatus::Verified,
                'country_code' => 'CM',
                'city' => 'Douala',
                'region' => 'Littoral',
                'email' => 'ops@bantufreight.example',
                'phone' => '+237 6 88 00 00 00',
                'description' => 'Bantu Freight Logistics moves containerised and bulk timber between the Douala/Kribi ports and inland mills.',
                'verified_at' => now(),
            ],
        );

        if ($company->type !== OrganisationType::Logistics) {
            $company->forceFill(['type' => OrganisationType::Logistics])->save();
        }

        $primaryTaken = DB::table('company_user')
            ->where('company_id', $company->getKey())
            ->where('is_primary', true)
            ->where('user_id', '!=', $logistics->getKey())
            ->exists();

        $logistics->companies()->syncWithoutDetaching([
            $company->getKey() => ['role' => 'owner', 'is_primary' => ! $primaryTaken],
        ]);

        $this->seedFleet($company);
    }

    /* ----------------------------------------------------- pending supplier */

    /**
     * A supplier company stuck at `CompanyStatus::Pending`, so the mobile
     * client's read-only/pending banner has a real account to render
     * against. Idempotent on the company slug and the `company_user` pivot;
     * the status is force-set back to Pending on every run so a stray
     * verification action elsewhere never silently "fixes" this persona.
     */
    private function wirePendingSupplier(User $pendingSupplier): void
    {
        $company = Company::firstOrCreate(
            ['slug' => Str::slug(self::PENDING_SUPPLIER_COMPANY)],
            [
                'legal_name' => self::PENDING_SUPPLIER_COMPANY,
                'trade_name' => 'Nkolbisson Timber',
                'type' => OrganisationType::Supplier,
                'status' => CompanyStatus::Pending,
                'country_code' => 'CM',
                'city' => 'Yaoundé',
                'region' => 'Centre',
                'email' => 'contact@nkolbissontimber.example',
                'phone' => '+237 6 77 00 00 00',
                'description' => 'Nkolbisson Timber Traders has applied to join the marketplace and is awaiting verification.',
            ],
        );

        if ($company->status !== CompanyStatus::Pending) {
            $company->forceFill(['status' => CompanyStatus::Pending])->save();
        }

        $primaryTaken = DB::table('company_user')
            ->where('company_id', $company->getKey())
            ->where('is_primary', true)
            ->where('user_id', '!=', $pendingSupplier->getKey())
            ->exists();

        $pendingSupplier->companies()->syncWithoutDetaching([
            $company->getKey() => ['role' => 'owner', 'is_primary' => ! $primaryTaken],
        ]);
    }

    /* ----------------------------------------------------------- notifications */

    /**
     * So the demo buyer's/supplier's/logistics's notification inbox is never
     * the one empty surface in an otherwise-populated demo. Every row here is
     * dispatched through the real notification classes against REAL existing
     * demo records (the RFQs/quotes/orders/messages the seeders above already
     * produced) — never a hand-crafted `data` array, so `reference`/`screen`
     * come straight out of each class's own `toArray()`.
     *
     * Idempotent: each persona's block is skipped once a notification of its
     * first seeded `type` already exists for that user.
     */
    private function seedNotifications(User $buyer, User $supplier, User $logistics): void
    {
        $this->seedBuyerNotifications($buyer);
        $this->seedSupplierNotifications($supplier);
        $this->seedLogisticsNotifications($logistics);
    }

    /**
     * Buyer: quote_received (RFQ-DEMO-00001's submitted quotes), order_status_changed
     * (the real awarded order, advanced to Shipped), payment_confirmed (a real
     * off-platform payment recorded on that same order via OrderService — the
     * exact service the live "record payment" action calls), and
     * message_received when MessagingSeeder has already produced a thread
     * with a supplier reply. dispute_reply is skipped: no Dispute exists
     * anywhere in the demo fixtures and nothing else seeds one, so inventing
     * one here would be a notification with no backing record.
     */
    private function seedBuyerNotifications(User $buyer): void
    {
        if ($buyer->notifications()->where('type', QuoteReceivedNotification::class)->exists()) {
            return;
        }

        $quote = Quote::query()
            ->whereHas('rfq', fn ($q) => $q->where('user_id', $buyer->getKey()))
            ->where('status', '!=', \App\Enums\QuoteStatus::Accepted)
            ->oldest('id')
            ->first();

        if ($quote !== null) {
            $buyer->notify(new QuoteReceivedNotification($quote));
            $this->backdateLatest($buyer, QuoteReceivedNotification::class, now()->subDays(3));
        }

        $order = Order::query()->where('user_id', $buyer->getKey())->latest('id')->first();

        if ($order !== null) {
            $from = $order->status;

            if ($order->status === OrderStatus::InProduction) {
                $order = app(OrderService::class)->ship($order);
            }

            if ($order->status !== $from) {
                $buyer->notify(new OrderStatusChangedNotification($order, $from, $order->status));
                $this->backdateLatest($buyer, OrderStatusChangedNotification::class, now()->subDays(1));
            }

            if ($order->payment_status === OrderPaymentStatus::Unpaid) {
                $order = app(OrderService::class)->recordPayment($order, (string) $order->total_amount, 'bank_transfer');
                $buyer->notify(new PaymentConfirmedNotification($order));
                $notification = $this->backdateLatest($buyer, PaymentConfirmedNotification::class, now()->subHours(2));
                $notification?->markAsRead();
            }

            $supplierMessage = Message::query()
                ->whereHas('conversation', fn ($q) => $q->where('user_id', $buyer->getKey()))
                ->where('sender_user_id', '!=', $buyer->getKey())
                ->latest('id')
                ->first();

            if ($supplierMessage !== null) {
                $buyer->notify(new MessageReceivedNotification($supplierMessage));
                $notification = $this->backdateLatest($buyer, MessageReceivedNotification::class, now()->subDays(5));
                $notification?->markAsRead();
            }
        }
    }

    /**
     * Supplier: rfq_routed (a real routing to the supplier's flagship demo
     * company), quote_accepted (the real quote that won the demo order, if
     * that company is the one that won it), and message_received mirroring
     * the buyer's thread from the supplier's side.
     */
    private function seedSupplierNotifications(User $supplier): void
    {
        if ($supplier->notifications()->where('type', RfqRoutedToExporter::class)->exists()) {
            return;
        }

        $companyIds = $supplier->companies()->pluck('companies.id');

        $routing = RfqCompany::query()
            ->whereIn('company_id', $companyIds)
            ->with('rfq')
            ->whereHas('rfq')
            ->oldest('id')
            ->first();

        if ($routing !== null && $routing->rfq !== null) {
            $company = Company::find($routing->company_id);

            if ($company !== null) {
                $supplier->notify(new RfqRoutedToExporter($routing->rfq, $company));
                $this->backdateLatest($supplier, RfqRoutedToExporter::class, now()->subDays(6));
            }
        }

        $winningQuote = Quote::query()
            ->whereIn('company_id', $companyIds)
            ->whereHas('rfq.orders')
            ->latest('id')
            ->first();

        if ($winningQuote !== null) {
            $supplier->notify(new QuoteAcceptedNotification($winningQuote));
            $notification = $this->backdateLatest($supplier, QuoteAcceptedNotification::class, now()->subDays(2));
            $notification?->markAsRead();
        }

        $buyerMessage = Message::query()
            ->whereHas('conversation', fn ($q) => $q->whereIn('company_id', $companyIds))
            ->whereHas('conversation', fn ($q) => $q->whereNotNull('user_id'))
            ->whereHas('sender', function ($q) use ($companyIds): void {
                $q->whereDoesntHave('companies', fn ($c) => $c->whereIn('companies.id', $companyIds));
            })
            ->latest('id')
            ->first();

        if ($buyerMessage !== null) {
            $supplier->notify(new MessageReceivedNotification($buyerMessage));
            $this->backdateLatest($supplier, MessageReceivedNotification::class, now()->subHours(6));
        }
    }

    /**
     * Logistics: no seeded notification type actually fits this persona.
     * `DocumentUploadedNotification` — the only class that resembles a
     * company/fleet document event — requires a real `Order` in its
     * constructor (`OrderLifecycleService::attachDocuments()` is its only
     * trigger), and the logistics persona's company is never a party to any
     * demo order: it exists solely to expose `GET /supplier/fleet/vehicles`
     * for an `OrganisationType::Logistics` company. There is currently no
     * notification class modelled around a company/vehicle/driver document,
     * so nothing is seeded here rather than forcing a fake fit.
     */
    private function seedLogisticsNotifications(User $logistics): void
    {
        // Intentionally empty — see the docblock above.
    }

    /**
     * The notification `notify()` just created is always the newest row for
     * this user of this class — fetch it and move its `created_at` back so
     * the inbox has realistic age variety instead of a single timestamp.
     */
    private function backdateLatest(User $user, string $type, \Illuminate\Support\Carbon $at): ?DatabaseNotification
    {
        $notification = $user->notifications()->where('type', $type)->latest('created_at')->first();

        $notification?->forceFill(['created_at' => $at])->save();

        return $notification;
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
