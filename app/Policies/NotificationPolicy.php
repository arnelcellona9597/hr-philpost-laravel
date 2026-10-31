<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

/**
 * Bell notifications belong to one user and are written by the system.
 */
class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isApproved();
    }

    public function view(User $user, Notification $notification): bool
    {
        return $this->owns($user, $notification);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Notification $notification): bool
    {
        return $this->owns($user, $notification);
    }

    public function delete(User $user, Notification $notification): bool
    {
        return $this->owns($user, $notification);
    }

    private function owns(User $user, Notification $notification): bool
    {
        return $user->isApproved() && $notification->user_id === $user->id;
    }
}
