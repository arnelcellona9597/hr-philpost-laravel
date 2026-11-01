<?php

namespace App\Models;

use App\Enums\BloodType;
use App\Enums\CivilStatus;
use App\Enums\Classification;
use App\Enums\EducationalAttainment;
use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Enums\Region;
use App\Models\Concerns\Audited;
use App\Services\EmploymentHistoryService;
use App\Services\LeaveService;
use App\Support\HrDate;
use App\Support\Names;
use BackedEnum;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['employee_id', 'profile_picture_id', 'last_name', 'first_name', 'middle_name', 'extension', 'gender', 'date_of_birth', 'civil_status', 'religion', 'blood_type', 'professional_eligibility', 'contact_number', 'email', 'address', 'educational_attainment', 'courses', 'year_level_earned_units', 'academic_honors', 'classification', 'employment_status', 'station_id', 'region', 'position', 'nature_of_work', 'driver_license_number', 'date_hired', 'last_day_of_service', 'remarks', 'sss', 'pagibig', 'tin', 'philhealth', 'emergency_contact_name', 'emergency_relationship', 'emergency_contact_number', 'emergency_address'])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use Audited, HasFactory;

    /**
     * Keep the stored "LAST, First M. Ext." copy used for sorting, searching and labels in step.
     */
    protected static function booted(): void
    {
        static::saving(function (Employee $employee): void {
            $employee->full_name = Names::lastFirst($employee->last_name, $employee->first_name, $employee->middle_name, $employee->extension);
        });

        // The latest employment period follows whatever is saved on the record.
        static::saved(function (Employee $employee): void {
            if ($employee->wasRecentlyCreated || $employee->wasChanged(['date_hired', 'last_day_of_service', 'employment_status', 'position', 'station_id', 'classification'])) {
                app(EmploymentHistoryService::class)->syncPeriodFromEmployee($employee);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'date_of_birth' => 'date:Y-m-d',
            'civil_status' => CivilStatus::class,
            'blood_type' => BloodType::class,
            'educational_attainment' => EducationalAttainment::class,
            'classification' => Classification::class,
            'employment_status' => EmploymentStatus::class,
            'region' => Region::class,
            'date_hired' => 'date:Y-m-d',
            'last_day_of_service' => 'date:Y-m-d',
        ];
    }

    /**
     * @return Attribute<int|null, never>
     */
    protected function age(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->date_of_birth ? HrDate::ageOn($this->date_of_birth->toDateString()) : null);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function lengthOfService(): Attribute
    {
        return Attribute::get(fn (): string => HrDate::formatSpan(HrDate::lengthOfService(
            $this->date_hired?->toDateString(),
            $this->last_day_of_service?->toDateString(),
        )));
    }

    /**
     * Wellness leave days counted against an employee's allowance for a year.
     */
    public function wellnessDaysUsedIn(int $year): int
    {
        return $this->wellnessLeaves()
            ->whereIn('status', LeaveService::COUNTED)
            ->get()
            ->sum(fn (WellnessLeave $leave): int => (int) ($leave->days_by_year[$year] ?? 0));
    }

    /**
     * Every time the person was employed, oldest first.
     *
     * @return HasMany<EmploymentPeriod, $this>
     */
    public function employmentPeriods(): HasMany
    {
        return $this->hasMany(EmploymentPeriod::class)->orderBy('hired_on');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'station_id');
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function profilePicture(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'profile_picture_id');
    }

    /**
     * @return HasMany<WellnessLeave, $this>
     */
    public function wellnessLeaves(): HasMany
    {
        return $this->hasMany(WellnessLeave::class);
    }

    /**
     * @return HasMany<ItrSubmission, $this>
     */
    public function itrSubmissions(): HasMany
    {
        return $this->hasMany(ItrSubmission::class);
    }

    /**
     * @return HasMany<SwornDeclaration, $this>
     */
    public function swornDeclarations(): HasMany
    {
        return $this->hasMany(SwornDeclaration::class);
    }

    /**
     * @return HasMany<PdsSubmission, $this>
     */
    public function pdsSubmissions(): HasMany
    {
        return $this->hasMany(PdsSubmission::class);
    }

    /**
     * @return HasMany<IpcrRating, $this>
     */
    public function ipcrRatings(): HasMany
    {
        return $this->hasMany(IpcrRating::class);
    }

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * @return HasMany<Certificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * A field of this record as a person reads it, for the profile page and its printout.
     *
     * @param  array{name: string, kind: string}  $field
     */
    public function fieldText(array $field): string
    {
        $value = $field['kind'] === 'branch' ? $this->station?->name : $this->getAttribute($field['name']);

        return match (true) {
            $value === null || $value === '' => '—',
            $field['kind'] === 'date' => HrDate::format($value),
            $value instanceof BackedEnum => $value->value,
            default => (string) $value,
        };
    }

    /**
     * Deleting would orphan history, so HR should change the status instead.
     */
    public function deleteBlocker(): ?string
    {
        $total = collect([
            $this->wellnessLeaves(),
            $this->itrSubmissions(),
            $this->swornDeclarations(),
            $this->pdsSubmissions(),
            $this->ipcrRatings(),
            $this->payslips(),
            $this->certificates(),
            $this->contracts(),
            $this->applications(),
        ])->sum(fn (HasMany $records): int => $records->count());

        return $total > 0
            ? "This employee has {$total} linked record(s) such as leave, requirements, payslips, certificates or applications. Set the employment status to Resigned, Retired or Terminated instead of deleting."
            : null;
    }
}
