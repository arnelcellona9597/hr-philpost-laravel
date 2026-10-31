<?php

namespace App\Policies;

use App\Models\User;

/**
 * Delivery rows are written by the mailer service only.
 */
class MessageRecipientPolicy
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
