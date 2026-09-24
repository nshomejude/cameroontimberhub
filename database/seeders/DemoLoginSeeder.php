<?php

namespace Database\Seeders;

use App\Enums\CompanyStatus;
use App\Enums\CarbonRegistryStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\OrganisationType;
use App\Enums\ProductStatus;
use App\Models\CarbonProject;
use App\Models\Capacity;
use App\Models\Company;
use App\Models\Document;
use App\Models\Driver;
use App\Models\LotTransformation;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Species;
use App\Models\TimberLot;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VerificationBadge;
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

    /** The dedicated demo company the `processor` persona owns. */
    private const PROCESSOR_COMPANY = 'Sanaga Sawmill Sarl';

    /** The dedicated demo company the `manufacturer` persona owns. */
    private const MANUFACTURER_COMPANY = 'Mvog-Betsi Furniture Works Sarl';

    /** The dedicated demo company the `artisan` persona owns. */
    private const ARTISAN_COMPANY = 'Atelier Ebang Menuiserie';

    /** The dedicated demo company the `retailer` persona owns. */
    private const RETAILER_COMPANY = 'Marché Mokolo Timber Yard Sarl';

    /** The dedicated demo company the `carbon_developer` persona owns. */
    private const CARBON_DEVELOPER_COMPANY = 'Dja Forest Carbon Sarl';

    public function run(): void
    {
        $this->ensurePrerequisites();

        $buyer = $this->persona('buyer');
        $supplier = $this->persona('supplier');
        $admin = $this->persona('admin');
        $logistics = $this->persona('logistics');
        $pendingSupplier = $this->persona('pending_supplier');
        $processor = $this->persona('processor');
        $manufacturer = $this->persona('manufacturer');
        $artisan = $this->persona('artisan');
        $retailer = $this->persona('retailer');
        $carbonDeveloper = $this->persona('carbon_developer');

        $this->wireBuyer($buyer);
        $this->wireSupplier($supplier);
        $this->wireAdmin($admin);
        $this->wireLogistics($logistics);
        $this->wirePendingSupplier($pendingSupplier);
        $this->wireProcessor($processor);
        $this->wireManufacturer($manufacturer);
        $this->wireArtisan($artisan);
        $this->wireRetailer($retailer);
        $this->wireCarbonDeveloper($carbonDeveloper);

        $this->seedNotifications($buyer, $supplier, $logistics);

        // Map pins for the "nearest sellers" screen (fills NULLs only).
        \App\Support\Geo\SeedCompanyCoordinates::apply();

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

    /* ----------------------------------------------------------- processor */

    /**
     * A dedicated `OrganisationType::Processor` company: verified, with
     * capacity rows (so it is directory-visible on the Transformation
     * Network) and a real, completed `LotTransformation` (2 input lots -> 1
     * output lot) so the demo shows an actual mass-balance record rather
     * than an empty shell. Idempotent on the company slug/legal_name, the
     * `company_user` pivot, the capacity capability/period pair, and the
     * transformation's own `firstOrCreate` on processor+type+processed_at.
     */
    private function wireProcessor(User $processor): void
    {
        $company = Company::firstOrCreate(
            ['slug' => Str::slug(self::PROCESSOR_COMPANY)],
            [
                'legal_name' => self::PROCESSOR_COMPANY,
                'trade_name' => 'Sanaga Sawmill',
                'type' => OrganisationType::Processor,
                'status' => CompanyStatus::Verified,
                'country_code' => 'CM',
                'city' => 'Edéa',
                'region' => 'Littoral',
                'email' => 'ops@sanagasawmill.example',
                'phone' => '+237 6 90 00 00 00',
                'description' => 'Sanaga Sawmill processes logs into sawn timber for the domestic and export markets.',
                'verified_at' => now(),
            ],
        );

        if ($company->type !== OrganisationType::Processor) {
            $company->forceFill(['type' => OrganisationType::Processor])->save();
        }

        $this->attachPrimary($processor, $company);

        Capacity::firstOrCreate(
            ['owner_type' => Company::class, 'owner_id' => $company->getKey(), 'capability' => 'Sawing'],
            ['quantity' => 500, 'unit' => 'm3', 'period' => 'month'],
        );

        Capacity::firstOrCreate(
            ['owner_type' => Company::class, 'owner_id' => $company->getKey(), 'capability' => 'Kiln drying'],
            ['quantity' => 150, 'unit' => 'm3', 'period' => 'month'],
        );

        if (! LotTransformation::query()->where('processor_company_id', $company->getKey())->exists()) {
            $speciesId = Species::query()->value('id');

            $inputOne = TimberLot::create($this->timberLotAttributes($company->getKey(), $speciesId, 40));
            $inputTwo = TimberLot::create($this->timberLotAttributes($company->getKey(), $speciesId, 25));
            $output = TimberLot::create($this->timberLotAttributes($company->getKey(), $speciesId, 52));

            LotTransformation::recordFor(
                processorCompanyId: $company->getKey(),
                transformationType: 'sawing',
                inputs: [
                    ['lot' => $inputOne, 'quantity' => 40],
                    ['lot' => $inputTwo, 'quantity' => 25],
                ],
                outputs: [
                    ['lot' => $output, 'quantity' => 52],
                ],
                processedAt: now()->subDays(4),
                notes: 'Demo sawing run: logs into Select & Better sawn timber.',
            );
        }
    }

    /* -------------------------------------------------------- manufacturer */

    /**
     * A dedicated `OrganisationType::Manufacturer` company: verified, with
     * capacity rows and a few real Active finished-goods Products so the
     * demo panel and marketplace both have something to show.
     */
    private function wireManufacturer(User $manufacturer): void
    {
        $company = Company::firstOrCreate(
            ['slug' => Str::slug(self::MANUFACTURER_COMPANY)],
            [
                'legal_name' => self::MANUFACTURER_COMPANY,
                'trade_name' => 'Mvog-Betsi Furniture',
                'type' => OrganisationType::Manufacturer,
                'status' => CompanyStatus::Verified,
                'country_code' => 'CM',
                'city' => 'Yaoundé',
                'region' => 'Centre',
                'email' => 'sales@mvogbetsifurniture.example',
                'phone' => '+237 6 91 00 00 00',
                'description' => 'Mvog-Betsi Furniture Works turns kiln-dried timber into finished furniture for the domestic market.',
                'verified_at' => now(),
            ],
        );

        if ($company->type !== OrganisationType::Manufacturer) {
            $company->forceFill(['type' => OrganisationType::Manufacturer])->save();
        }

        $this->attachPrimary($manufacturer, $company);

        Capacity::firstOrCreate(
            ['owner_type' => Company::class, 'owner_id' => $company->getKey(), 'capability' => 'Furniture'],
            ['quantity' => 200, 'unit' => 'units', 'period' => 'month'],
        );

        Capacity::firstOrCreate(
            ['owner_type' => Company::class, 'owner_id' => $company->getKey(), 'capability' => 'CNC'],
            ['quantity' => 80, 'unit' => 'units', 'period' => 'month'],
        );

        if ($company->products()->count() < 3) {
            foreach (['Iroko Dining Table', 'Sapele Bookshelf', 'Bubinga Office Desk'] as $name) {
                Product::firstOrCreate(
                    ['company_id' => $company->getKey(), 'name' => $name],
                    $this->productAttributes($company->getKey(), $name),
                );
            }
        }
    }

    /* -------------------------------------------------------------- artisan */

    /**
     * A dedicated `OrganisationType::Artisan` company: verified, with a
     * handful of small-batch Active Products so it is real inventory the
     * domestic/local marketplace search can find (DomesticMarketplaceService
     * just filters Product/Company — nothing else needed).
     */
    private function wireArtisan(User $artisan): void
    {
        $company = Company::firstOrCreate(
            ['slug' => Str::slug(self::ARTISAN_COMPANY)],
            [
                'legal_name' => self::ARTISAN_COMPANY,
                'trade_name' => 'Atelier Ebang',
                'type' => OrganisationType::Artisan,
                'status' => CompanyStatus::Verified,
                'country_code' => 'CM',
                'city' => 'Bafoussam',
                'region' => 'West',
                'email' => 'contact@atelierebang.example',
                'phone' => '+237 6 92 00 00 00',
                'description' => 'Atelier Ebang Menuiserie is a small-batch woodworking artisan making stools, boxes and carved decor.',
                'verified_at' => now(),
                'logo_path' => 'companies/demo/logo.png',
            ],
        );

        if ($company->type !== OrganisationType::Artisan) {
            $company->forceFill(['type' => OrganisationType::Artisan])->save();
        }

        $this->attachPrimary($artisan, $company);

        if ($company->products()->count() < 3) {
            foreach (['Carved Ebony Stool', 'Bamboo Storage Box', 'Hand-Carved Wall Decor'] as $name) {
                Product::firstOrCreate(
                    ['company_id' => $company->getKey(), 'name' => $name],
                    $this->productAttributes($company->getKey(), $name),
                );
            }
        }

        $this->ensurePubliclyVisible($company);
    }

    /* ------------------------------------------------------------- retailer */

    /**
     * A dedicated `OrganisationType::Retailer` company: verified, with a
     * region/city set (so local-market search filters have something real
     * to match) and a few Active Products representing yard stock.
     */
    private function wireRetailer(User $retailer): void
    {
        $company = Company::firstOrCreate(
            ['slug' => Str::slug(self::RETAILER_COMPANY)],
            [
                'legal_name' => self::RETAILER_COMPANY,
                'trade_name' => 'Marché Mokolo Timber Yard',
                'type' => OrganisationType::Retailer,
                'status' => CompanyStatus::Verified,
                'country_code' => 'CM',
                'city' => 'Yaoundé',
                'region' => 'Centre',
                'email' => 'yard@marchemokolotimber.example',
                'phone' => '+237 6 93 00 00 00',
                'description' => 'Marché Mokolo Timber Yard stocks sawn timber and boards for local walk-in buyers.',
                'verified_at' => now(),
                'logo_path' => 'companies/demo/logo.png',
            ],
        );

        if ($company->type !== OrganisationType::Retailer) {
            $company->forceFill(['type' => OrganisationType::Retailer])->save();
        }

        $this->attachPrimary($retailer, $company);

        if ($company->products()->count() < 4) {
            foreach ([
                'Ayous Sawn Timber 2x4',
                'Iroko Plank Bundle',
                'Sapele Board 25mm',
                'Framire Squared Timber',
            ] as $name) {
                Product::firstOrCreate(
                    ['company_id' => $company->getKey(), 'name' => $name],
                    $this->productAttributes($company->getKey(), $name),
                );
            }
        }

        $this->ensurePubliclyVisible($company);
    }

    /* ------------------------------------------------------ carbon developer */

    /**
     * A dedicated `OrganisationType::CarbonDeveloper` company: verified,
     * with a real `CarbonProject` (a valid GeoJSON boundary, area, estimated
     * credits) advanced from Draft to Submitted — the only legal transition
     * `CarbonRegistryStatus::Draft` allows — so the demo shows a project
     * mid-registry rather than a blank Draft.
     */
    private function wireCarbonDeveloper(User $carbonDeveloper): void
    {
        $company = Company::firstOrCreate(
            ['slug' => Str::slug(self::CARBON_DEVELOPER_COMPANY)],
            [
                'legal_name' => self::CARBON_DEVELOPER_COMPANY,
                'trade_name' => 'Dja Forest Carbon',
                'type' => OrganisationType::CarbonDeveloper,
                'status' => CompanyStatus::Verified,
                'country_code' => 'CM',
                'city' => 'Djoum',
                'region' => 'South',
                'email' => 'projects@djaforestcarbon.example',
                'phone' => '+237 6 94 00 00 00',
                'description' => 'Dja Forest Carbon develops REDD+ carbon projects around the Dja Faunal Reserve buffer zone.',
                'verified_at' => now(),
            ],
        );

        if ($company->type !== OrganisationType::CarbonDeveloper) {
            $company->forceFill(['type' => OrganisationType::CarbonDeveloper])->save();
        }

        $this->attachPrimary($carbonDeveloper, $company);

        $project = CarbonProject::query()->where('company_id', $company->getKey())->first();

        if ($project === null) {
            $project = CarbonProject::create([
                'company_id' => $company->getKey(),
                'name' => 'Dja Buffer Zone Reforestation',
                'project_type' => 'reforestation',
                'region' => 'South',
                'description' => 'Community reforestation of the Dja Faunal Reserve buffer zone.',
                'status' => 'active',
                'area_hectares' => 1250.50,
                'estimated_credits_per_year' => 8400.00,
                'boundary' => [
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [12.5000, 2.8000],
                        [12.5400, 2.8000],
                        [12.5400, 2.8400],
                        [12.5000, 2.8400],
                        [12.5000, 2.8000],
                    ]],
                ],
            ]);
        }

        if ($project->registry_status === CarbonRegistryStatus::Draft
            && $project->registry_status->canTransitionTo(CarbonRegistryStatus::Submitted)) {
            $project->transitionTo(CarbonRegistryStatus::Submitted);
        }
    }

    /**
     * Satisfies every `Company::scopePubliclyVisible()` predicate (verified
     * status, logo_path, description, region, a species, a public contact,
     * an active verification badge) so this persona's products actually
     * appear in `DomesticMarketplaceService`'s search — mirrors
     * `DomesticMarketDemoSeeder::ensurePubliclyVisible()` exactly, the
     * established pattern for a "real, findable" demo company. `logo_path`
     * is set on the company's own `firstOrCreate()` call above; this
     * back-fills it on a company that already existed before that field was
     * added, so a re-run of an already-seeded persona still becomes visible.
     */
    private function ensurePubliclyVisible(Company $company): void
    {
        if (blank($company->logo_path)) {
            $company->forceFill(['logo_path' => 'companies/demo/logo.png'])->save();
        }

        $company->contacts()->firstOrCreate(
            ['company_id' => $company->getKey(), 'is_public' => true],
            ['name' => 'Sales Desk', 'title' => 'Sales', 'email' => $company->email, 'phone' => $company->phone],
        );

        if ($company->species()->doesntExist()) {
            $speciesId = Species::query()->value('id');

            if ($speciesId !== null) {
                $company->species()->syncWithoutDetaching([$speciesId]);
            }
        }

        VerificationBadge::firstOrCreate(
            ['company_id' => $company->getKey()],
            [
                'badge_type' => \App\Enums\BadgeType::VerifiedExporter,
                'status' => \App\Enums\BadgeStatus::Active,
                'issued_at' => now(),
                'valid_until' => now()->addYear()->toDateString(),
                'is_public' => true,
                'reference_code' => 'CTH-DOM-'.str_pad((string) $company->getKey(), 4, '0', STR_PAD_LEFT),
            ],
        );
    }

    /**
     * A literal TimberLot attribute set — deliberately NOT TimberLot::factory(),
     * which requires fakerphp/faker. That package is require-dev only and is
     * absent from a `composer install --no-dev` production deploy, so a
     * factory call here would fatal in production (as it did the first time
     * this method didn't exist). Every other persona in this seeder already
     * avoids factories for the same reason.
     *
     * @return array<string, mixed>
     */
    private function timberLotAttributes(int $companyId, ?int $speciesId, float $volumeM3): array
    {
        return [
            'company_id' => $companyId,
            'species_id' => $speciesId,
            'product_form' => 'sawn_timber',
            'grade' => 'Select & Better',
            'quantity' => $volumeM3,
            'volume_m3' => $volumeM3,
            'unit' => 'm3',
            'origin_country' => 'CM',
            'origin_region' => 'Littoral',
            'available_quantity' => $volumeM3,
            'reserved_quantity' => 0,
            'price_currency' => 'XAF',
            'legality_evidence_status' => 'not_assessed',
            'traceability_status' => 'not_traceable',
            'inspection_status' => 'not_inspected',
            'status' => \App\Enums\TimberLotStatus::Available,
        ];
    }

    /**
     * A literal Product attribute set — see timberLotAttributes()'s docblock
     * for why this avoids Product::factory() (fakerphp/faker is absent from
     * a production `composer install --no-dev`).
     *
     * @return array<string, mixed>
     */
    private function productAttributes(int $companyId, string $name): array
    {
        return [
            'company_id' => $companyId,
            'species_id' => Species::query()->value('id'),
            'name' => $name,
            'product_type' => \App\Enums\ProductType::SawnTimber,
            'description' => 'Demo listing seeded for the '.$name.' persona showcase.',
            'price_amount' => 450000,
            'price_currency' => 'XAF',
            'price_unit' => \App\Enums\PriceUnit::CubicMetre,
            'moq_quantity' => 20,
            'moq_unit' => \App\Enums\PriceUnit::CubicMetre,
            'grade' => 'Select & Better',
            'thickness_mm' => 50,
            'width_min_mm' => 100,
            'width_max_mm' => 250,
            'length_min_m' => 2.4,
            'length_max_m' => 6.0,
            'moisture_content' => '12% - 15% (KD)',
            'origin' => 'Cameroon',
            'certification' => 'Legal Origin Verified',
            'status' => ProductStatus::Active,
            'is_featured' => false,
            'is_best_seller' => false,
            'reviews_count' => 0,
            'buyers_count' => 0,
        ];
    }

    /**
     * Shared "make this persona the (or a) primary member of this company"
     * logic, factored out of the logistics/pending_supplier blocks above —
     * never steals the primary seat from a real owner.
     */
    private function attachPrimary(User $user, Company $company): void
    {
        $primaryTaken = DB::table('company_user')
            ->where('company_id', $company->getKey())
            ->where('is_primary', true)
            ->where('user_id', '!=', $user->getKey())
            ->exists();

        $user->companies()->syncWithoutDetaching([
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
