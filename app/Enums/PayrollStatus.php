<?php

namespace App\Enums;

/**
 * A payroll period moves forward one step at a time: HR prepares it (Draft), a reviewer checks the
 * rates and attendance (Reviewed), a System Admin approves it (Approved) and releases it (Released).
 */
enum PayrollStatus: string
{
    case Draft = 'Draft';
    case Reviewed = 'Reviewed';
    case Approved = 'Approved';
    case Released = 'Released';

    /**
     * The badge class shown beside the status.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'badge-warning',
            self::Reviewed => 'badge-neutral',
            self::Approved => 'badge-primary',
            self::Released => 'badge-success',
        };
    }
}
