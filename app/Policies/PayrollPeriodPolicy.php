<?php

namespace App\Policies;

use App\Models\User;

/**
 * Any HR account prepares and reviews payroll; only a System Admin approves, releases, sends a period
 * back to draft or corrects released payslips.
 */
class PayrollPeriodPolicy extends HrRecordPolicy
{
    public function review(User $user): bool
    {
        return $user->isApproved();
    }

    public function approve(User $user): bool
    {
        return $user->isSystemAdmin();
    }

    public function release(User $user): bool
    {
        return $user->isSystemAdmin();
    }

    public function returnToDraft(User $user): bool
    {
        return $user->isSystemAdmin();
    }

    public function correctReleased(User $user): bool
    {
        return $user->isSystemAdmin();
    }
}
