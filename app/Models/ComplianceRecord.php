<?php

namespace App\Models;

use App\Support\Deadlines;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's annual requirement for a year. The deadline and timeliness are computed on read,
 * so changing a deadline re-evaluates every record of that year.
 */
abstract class ComplianceRecord extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'date_submitted' => 'date:Y-m-d',
            'date_received' => 'date:Y-m-d',
        ];
    }

    /**
     * The IPCR rating period, for records that have one.
     */
    public function ratingPeriodValue(): ?string
    {
        return null;
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function deadline(): Attribute
    {
        return Attribute::get(fn (): ?string => Deadlines::for(
            RequirementDeadlineSetting::current()->toArray(),
            static::SLUG,
            $this->year,
            $this->ratingPeriodValue(),
        ));
    }

    /**
     * @return Attribute<array{status: string, days: int, label: string}, never>
     */
    protected function timeliness(): Attribute
    {
        return Attribute::get(fn (): array => Deadlines::timeliness($this->date_submitted, $this->deadline));
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
