<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Models\Concerns\Audited;
use App\Services\EmploymentHistoryService;
use App\Support\HrDate;
use Database\Factories\EmploymentPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stretch of employment: from a hire date to a separation date, or still open. An employee who
 * leaves and comes back has one period for each time. The employee's own hire date, last day and
 * status always mirror the latest period.
 */
#[Fillable(['employee_id', 'hired_on', 'separated_on', 'separation_type', 'position', 'station_id', 'classification', 'remarks'])]
class EmploymentPeriod extends Model
{
    /** @use HasFactory<EmploymentPeriodFactory> */
    use Audited, HasFactory;

    protected static function booted(): void
    {
        $mirror = fn (EmploymentPeriod $period) => app(EmploymentHistoryService::class)->syncEmployeeFromLatest(Employee::query()->find($period->employee_id));

        static::saved($mirror);
        static::deleted($mirror);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hired_on' => 'date:Y-m-d',
            'separated_on' => 'date:Y-m-d',
            'separation_type' => EmploymentStatus::class,
            'classification' => Classification::class,
        ];
    }

    /**
     * How the period reads in the audit log: "Dela Cruz, Juan: Mar 5, 2018 – present".
     *
     * @return Attribute<string, never>
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => ($this->employee?->full_name ?? 'Employee').': '.HrDate::format($this->hired_on).' – '.($this->separated_on ? HrDate::format($this->separated_on) : 'present'));
    }

    /**
     * Whether the person is still employed in this period.
     */
    public function isOpen(): bool
    {
        return $this->separated_on === null && $this->separation_type === null;
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'station_id');
    }
}
