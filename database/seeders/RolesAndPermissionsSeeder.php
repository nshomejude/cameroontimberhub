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
        'rfqs.triage',
        'rfqs.route',
        'pages.manage',
        'plans.manage',
        'users.manage',
        'audit.view',
    ];

    /** Role => permission matrix (spec decision G). super_admin gets all. */
    public const MATRIX = [
        'admin' => [
            'companies.view', 'companies.manage',
            'documents.review', 'verification.review',
            'badges.issue', 'badges.revoke',
            'species.manage', 'rfqs.triage', 'rfqs.route',
            'pages.manage', 'audit.view',
        ],
        'verification_officer' => [
            'companies.view', 'documents.review', 'verification.review',
            'badges.issue', 'badges.revoke', 'audit.view',
        ],
        'content_manager' => [
            'companies.view', 'species.manage', 'pages.manage',
        ],
    ];

    /** Seedable-but-unused-in-MVP platform roles (no permissions yet). */
    public const FUTURE_ROLES = ['sales_officer', 'finance_officer', 'support_officer'];

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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
