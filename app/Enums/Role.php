<?php

namespace App\Enums;

enum Role: string
{
    case SystemAdmin = 'system-admin';
    case HrStaff = 'hr-staff';

    public function label(): string
    {
        return match ($this) {
            self::SystemAdmin => 'System Admin',
            self::HrStaff => 'HR Staff',
        };
    }
}
