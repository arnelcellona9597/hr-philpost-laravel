<?php

namespace App\Policies;

use App\Models\User;

/**
 * Import history is written by the import service only.
 */
class ImportJobPolicy
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
        return false;
    }

    public function update(User $user): bool
    {
        return false;
    }

    public function delete(User $user): bool
    {
        return false;
    }
}
