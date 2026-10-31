<?php

namespace App\Support;

use BackedEnum;

/**
 * Badge classes for statuses.
 */
class Badge
{
    public const TIMELINESS = [
        'on-time' => 'badge-success',
        'late' => 'badge-danger',
        'submitted' => 'badge-neutral',
        'overdue' => 'badge-danger',
        'pending' => 'badge-warning',
    ];

    public const REQUIREMENT = [
        'Pending' => 'badge-warning',
        'Submitted' => 'badge-primary',
        'Verified' => 'badge-success',
        'N/A' => 'badge-neutral',
    ];

    public static function for(BackedEnum|string|null $status): string
    {
        $value = $status instanceof BackedEnum ? $status->value : $status;

        return match ($value) {
            'Active', 'Approved', 'Complete', 'Hired', 'approved', 'Submitted', 'Verified' => 'badge-success',
            'Pending', 'pending', 'For Interview', 'For OJT', 'Incomplete' => 'badge-warning',
            'Terminated', 'Disapproved', 'Not Hired', 'disabled' => 'badge-danger',
            default => 'badge-neutral',
        };
    }
}
