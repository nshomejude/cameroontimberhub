<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the canonical platform RBAC defined in the MVP spec (decision G).
 *
 * Platform staff are authorized via these spatie roles on the single `web`
 * guard. Company-side authorization (owner/manager/member) is governed by the
 * company_user pivot, NOT by spatie roles, and is therefore not seeded here.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** Canonical permission slugs (spec decision G). */
    public const PERMISSIONS = [
        'companies.view',
        'companies.manage',
        'companies.suspend',
        'documents.review',
        'verification.review',
        'badges.issue',
        'badges.revoke',
        'species.manage',
        'products.manage',
        'rfqs.triage',
        'rfqs.route',
        'inquiries.review',
        'pages.manage',
        'plans.manage',
        'users.manage',
        'audit.view',
        'certificates.manage',
        // Added for admin governance segregation (blueprint §88): compliance
        // authority (ComplianceRule/ComplianceCase/RegulatorySource,
        // Inspectors/Inspections) is distinct from verification authority and
        // from billing/financial authority below.
        'compliance.manage',
        'payments.view',
        // Gates the admin PaymentSettings + payment-credential change
        // request/approve resources (billing engine M12). super_admin +
        // finance_officer only — deliberately narrow, since approving a
        // live-credential change also requires being a different admin than
        // the requester plus a fresh 2FA confirmation (App\Actions\Payments).
        'payments.manage',
        // Gates the admin Tax Rules resource (billing engine M5, plan §7.3
        // / §124 "admin-configurable, no deploy"). Tax configuration is
        // financial authority, so this is granted alongside payments.manage
        // (super_admin + finance_officer only) and deliberately NOT to the
        // broader `admin` role — consistent with payments.manage.
        'pricing.manage',
        // Gates the admin Invoices + Credit Notes resources (billing engine
        // M4). Financial/billing authority — granted to super_admin, `admin`
        // and `finance_officer`. The public /billing/invoices/{invoice}
        // print view is authorised by company membership, NOT this permission.
        'billing.view',
        // Added for the Market Intelligence dashboard (blueprint §33-34):
        // gates the Price/Demand/Supplier Performance index page.
        'market-intelligence.view',
        // Blueprint §25 anti-fraud detection: reviewing/dismissing/confirming
        // FraudSignal rows. Admin/super_admin only — deliberately not granted
        // to any of the narrower staff roles below.
        'fraud.review',
        // Added for the Platform Operations dashboard (blueprint §66-69):
        // gates the North-Star KPI page. Admin/super_admin only.
        'platform-ops.view',
        // Added for the formal Dispute Resolution workflow (blueprint §64):
        // gates the admin Dispute decision action. Admin/super_admin only.
        'disputes.manage',
        // Gates the AI provider settings + API key change request/approve
        // resources. Admin/super_admin only — deliberately narrow, since
        // approving a key change also requires being a different admin than
        // the requester plus a fresh 2FA confirmation (see App\Actions\Ai).
        'ai.manage',
        // Gates the `/api/v1` product API key issuance/revocation resource
        // (blueprint: API-First plan Task 0.4). Admin/super_admin only —
        // deliberately narrow, since approving issuance also requires being
        // a different admin than the requester plus a fresh 2FA
        // confirmation (see App\Actions\ApiKeys).
        'api-keys.manage',
    ];

    /** Role => permission matrix (spec decision G). super_admin gets all. */
    public const MATRIX = [
        'admin' => [
            'companies.view', 'companies.manage',
            'documents.review', 'verification.review',
            'badges.issue', 'badges.revoke',
            'species.manage', 'products.manage',
            'rfqs.triage', 'rfqs.route', 'inquiries.review',
            'pages.manage', 'audit.view',
            'certificates.manage',
            // Additive (blueprint §88): admin already had de facto access to
            // every critical system, so these newly-introduced granular
            // permissions are added here too -- nothing existing is removed.
            'compliance.manage', 'payments.view',
            // Invoices + Credit Notes admin resources (billing engine M4).
            'billing.view',
            // Market Intelligence dashboard (blueprint §33-34): admin-only.
            'market-intelligence.view',
            // Blueprint §25 anti-fraud detection: admin-only.
            'fraud.review',
            // Platform Operations dashboard (blueprint §66-69): admin-only.
            'platform-ops.view',
            // Dispute Resolution workflow (blueprint §64): admin-only.
            'disputes.manage',
            // AI provider settings + key-change request/approve: admin-only.
            'ai.manage',
            // `/api/v1` product API key issuance/revocation: admin-only.
            'api-keys.manage',
        ],
        'verification_officer' => [
            'companies.view', 'documents.review', 'verification.review',
            'badges.issue', 'badges.revoke', 'audit.view',
            'certificates.manage',
        ],
        'content_manager' => [
            'companies.view', 'species.manage', 'pages.manage',
        ],
        // Compliance authority (blueprint §88, §89 segregation): manages
        // regulatory sources, compliance rules/cases, inspectors and
        // inspections. Deliberately excludes plans.manage/payments.view
        // (billing) and users.manage (privilege escalation).
        'compliance_officer' => [
            'companies.view', 'compliance.manage', 'audit.view',
        ],
        // Financial/billing authority (blueprint §88, §89 segregation):
        // manages subscription plans and can view payments. Deliberately
        // excludes verification.review/badges.* and compliance.manage.
        'billing_officer' => [
            'plans.manage', 'payments.view', 'audit.view',
        ],
        // Payment/gateway-credential authority (billing engine plan §7.6):
        // holds payments.manage so it can propose/approve gateway credential
        // changes. billing.refund / pricing.manage arrive in later phases.
        'finance_officer' => [
            'payments.manage',
            'pricing.manage',
            'billing.view',
        ],
    ];

    /** Seedable-but-unused-in-MVP platform roles (no permissions yet). */
    public const FUTURE_ROLES = ['sales_officer', 'support_officer'];

    /**
     * Account-capability roles (brief §3.1) -- what a USER account can DO on
     * the platform, distinct from the staff roles above which gate the
     * /admin Filament panel. Spatie roles are many-to-many, so one user can
     * hold several of these at once (the brief's own example: a sawmill
     * account is both `supplier` and `buyer`).
     *
     * Only `buyer` and `supplier` are wired to real behaviour today
     * (EnsureBuyerAccount). The rest are seeded so they exist and can be
     * assigned, but have no feature gate yet -- see gap-plan item 0.7b for
     * why (each depends on an entity/workflow that doesn't exist yet:
     * Processor directory, Carbon Project, Logistics/telematics).
     */
    public const ACCOUNT_ROLES = [
        'buyer',      // wired: EnsureBuyerAccount gates /account.
        'supplier',   // wired: auto-assigned on company ownership.
        'processor',  // gate at a processor self-service surface; today only the
                      // read-only /transformation-network directory exists (keyed
                      // on OrganisationType, not the account role) — nothing to gate.
        'artisan',    // gate at artisan self-service; today only the read-only
                      // /companies/{slug}/portfolio page exists (keyed on
                      // OrganisationType::Artisan) — nothing to gate yet.
        'carbon_developer', // wired: complements the OrganisationType::CarbonDeveloper
                            // gate on the exporter CarbonProjectResource; full role
                            // gate lands with the Batch F carbon registry.
        'carbon_buyer',     // gate once carbon-credit purchasing/retirement exists
                            // (blueprint §2.7) — no surface today.
        'logistics_partner', // wired: gates the exporter Vehicle/Driver fleet
                             // resources (OR an OrganisationType::Logistics company).
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Refresh the registrar cache so newly-created permissions resolve
        // during the role assignment below.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // super_admin holds every permission.
        Role::findOrCreate('super_admin', 'web')->syncPermissions(self::PERMISSIONS);

        foreach (self::MATRIX as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }

        foreach (self::FUTURE_ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        foreach (self::ACCOUNT_ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
