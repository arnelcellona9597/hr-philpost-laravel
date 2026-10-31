<?php

namespace App\Enums;

enum LeaveStatus: string
{
    case Approved = 'Approved';
    case Pending = 'Pending';
    case Disapproved = 'Disapproved';
    case Cancelled = 'Cancelled';

    /**
     * Whether a filing with this status uses up allowance and blocks overlapping filings.
     */
    public function consumesAllowance(): bool
    {
        return $this === self::Approved || $this === self::Pending;
    }
}
