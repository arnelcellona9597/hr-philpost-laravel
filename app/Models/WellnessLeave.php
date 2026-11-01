<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use App\Models\Concerns\Audited;
use Database\Factories\WellnessLeaveFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'last_name', 'first_name', 'middle_name', 'date_filing', 'inclusive_date_from', 'inclusive_date_to', 'date_received', 'status', 'days', 'year', 'days_by_year', 'remarks'])]
class WellnessLeave extends Model
{
    /** @use HasFactory<WellnessLeaveFactory> */
    use Audited, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_filing' => 'date:Y-m-d',
            'inclusive_date_from' => 'date:Y-m-d',
            'inclusive_date_to' => 'date:Y-m-d',
            'date_received' => 'date:Y-m-d',
            'status' => LeaveStatus::class,
            'days' => 'integer',
            'year' => 'integer',
            'days_by_year' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
