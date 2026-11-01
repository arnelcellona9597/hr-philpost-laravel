<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\IsSingleton;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['leave_filed_to_employee', 'leave_status_to_employee', 'hired_requirements_to_applicant', 'payroll_released_to_employee', 'registration_to_admins', 'hourly_limit', 'reply_to', 'footer'])]
#[Hidden(['queue_key'])]
class NotificationSetting extends Model
{
    use Audited, IsSingleton;

    /**
     * Values for the row created on a fresh install.
     *
     * @return array<string, mixed>
     */
    protected static function defaultValues(): array
    {
        return [
            'footer' => 'This is an automated message from the HR office.',
            'queue_key' => bin2hex(random_bytes(18)),
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leave_filed_to_employee' => 'boolean',
            'leave_status_to_employee' => 'boolean',
            'hired_requirements_to_applicant' => 'boolean',
            'payroll_released_to_employee' => 'boolean',
            'registration_to_admins' => 'boolean',
            'hourly_limit' => 'integer',
            'smtp_last_error_at' => 'datetime',
            'smtp_last_ok_at' => 'datetime',
        ];
    }
}
