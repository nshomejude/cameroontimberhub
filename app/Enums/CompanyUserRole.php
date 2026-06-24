<?php

namespace App\Enums;

enum CompanyUserRole: string
{
    case Owner   = 'owner';
    case Manager = 'manager';
    case Member  = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Owner   => 'Owner',
            self::Manager => 'Manager',
            self::Member  => 'Member',
        };
    }

    /** Roles that can mutate company-level records. */
    public function canManage(): bool
    {
        return match ($this) {
            self::Owner, self::Manager => true,
            self::Member               => false,
        };
    }
}
