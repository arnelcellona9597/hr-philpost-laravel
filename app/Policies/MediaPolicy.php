<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;

/**
 * Files are personal data under RA 10173, so only signed-in staff read them unless marked public.
 */
class MediaPolicy extends HrRecordPolicy
{
    public function view(?User $user, ?Media $media = null): bool
    {
        return ($user?->isApproved() ?? false) || ($media?->is_public ?? false);
    }

    /**
     * Making a file public exposes it without login.
     */
    public function publish(User $user): bool
    {
        return $user->isSystemAdmin();
    }
}
