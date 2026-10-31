<?php

namespace App\Policies;

use App\Models\User;

/**
 * Standard access for HR records: every approved HR user can view, add, edit and delete.
 */
abstract class HrRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isApproved();
    }

    public function view(User $user): bool
    {
        return $user->isApproved();
    }

    public function create(User $user): bool
    {
        return $user->isApproved();
    }

    public function update(User $user): bool
    {
        return $user->isApproved();
    }

    public function delete(User $user): bool
    {
        return $user->isApproved();
    }
}
