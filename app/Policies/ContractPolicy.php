<?php

namespace App\Policies;

use App\Models\User;

class ContractPolicy extends HrRecordPolicy
{
    /**
     * Only System Admins remove a contract from the numbered log. Staff void it instead.
     */
    public function delete(User $user): bool
    {
        return $user->isSystemAdmin();
    }
}
