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
    ];

    /** Seedable-but-unused-in-MVP platform roles (no permissions yet). */
    public const FUTURE_ROLES = ['sales_officer', 'finance_officer', 'support_officer'];

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
        'buyer',
        'supplier',
        'processor',
        'artisan',
        'carbon_developer',
        'carbon_buyer',
        'logistics_partner',
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
