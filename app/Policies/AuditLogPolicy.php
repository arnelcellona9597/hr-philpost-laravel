<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * History is written only by the system and nobody can edit or delete it. HR Staff see HR records;
 * changes to HR accounts and SMTP settings are visible to System Admins only.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isApproved();
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->isSystemAdmin()
            || ($user->isApproved() && ! in_array($auditLog->collection_slug, AuditLog::ADMIN_ONLY_COLLECTIONS, true));
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
