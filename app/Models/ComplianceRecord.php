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
     * Details copied from the employee, beyond their name.
     *
     * @var list<string>
     */
    public const SNAPSHOT = [];

    /**
     * Fill blank snapshot details from the employee, and replace them when the employee changes.
     */
    protected static function booted(): void
    {
        static::saving(fn (ComplianceRecord $record) => $record->copyEmployeeDetails());
    }

    /**
     * The model behind a requirement's URL segment.
     *
     * @return class-string<ComplianceRecord>
     */
    public static function modelFor(string $route): string
    {
        return match ($route) {
            'itr' => ItrSubmission::class,
            'sworn-declaration' => SwornDeclaration::class,
            'pds' => PdsSubmission::class,
            'ipcr' => IpcrRating::class,
            default => abort(404),
        };
    }

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

    private function copyEmployeeDetails(): void
    {
        $employee = Employee::query()->with('station')->find($this->employee_id);

        if ($employee === null) {
            return;
        }

        $replace = $this->exists && $this->isDirty('employee_id');
        $source = [
            'last_name' => $employee->last_name,
            'first_name' => $employee->first_name,
            'middle_name' => $employee->middle_name,
            'extension' => $employee->extension,
            'station' => $employee->station?->name,
            'job_title' => $employee->position,
            'tin' => $employee->tin,
        ];

        foreach (['last_name', 'first_name', 'middle_name', ...static::SNAPSHOT] as $key) {
            if ($replace || blank($this->getAttribute($key))) {
                $this->setAttribute($key, $source[$key]);
            }
        }
    }
}
