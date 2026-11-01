<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\OnboardingRequirement;
use App\Enums\RequirementsCompletion;
use App\Enums\RequirementStatus;
use App\Models\Concerns\Audited;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'applicant_name', 'email', 'position_applied', 'station_id', 'replacement', 'date_applied', 'start_ojt', 'application_status', 'req_pds', 'req_specimen_sign', 'req_sworn_declaration', 'req_tin_verification', 'req_police_clearance', 'req_medical_lab', 'req_landbank_account', 'req_assumption_of_duty', 'req_driver_license', 'req_or_cr', 'date_submitted', 'remarks', 'employee_id'])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use Audited, HasFactory;

    /**
     * Keep the computed requirement summary and the Hired timestamp in step with each save.
     */
    protected static function booted(): void
    {
        static::saving(function (Application $application): void {
            $missing = count($application->missingRequirements());

            $application->requirements_missing = $missing;
            $application->requirements_status = $missing === 0 ? RequirementsCompletion::Complete : RequirementsCompletion::Incomplete;

            if ($application->application_status !== ApplicationStatus::Hired) {
                $application->hired_at = null;
            } elseif ($application->getOriginal('application_status') !== ApplicationStatus::Hired) {
                $application->hired_at = now();
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
            'date_applied' => 'date:Y-m-d',
            'start_ojt' => 'date:Y-m-d',
            'application_status' => ApplicationStatus::class,
            'req_pds' => RequirementStatus::class,
            'req_specimen_sign' => RequirementStatus::class,
            'req_sworn_declaration' => RequirementStatus::class,
            'req_tin_verification' => RequirementStatus::class,
            'req_police_clearance' => RequirementStatus::class,
            'req_medical_lab' => RequirementStatus::class,
            'req_landbank_account' => RequirementStatus::class,
            'req_assumption_of_duty' => RequirementStatus::class,
            'req_driver_license' => RequirementStatus::class,
            'req_or_cr' => RequirementStatus::class,
            'date_submitted' => 'date:Y-m-d',
            'requirements_status' => RequirementsCompletion::class,
            'requirements_missing' => 'integer',
            'hired_at' => 'datetime',
            'requirements_email_sent_at' => 'datetime',
        ];
    }

    /**
     * Labels of the onboarding documents still outstanding. Only Pending counts as missing.
     *
     * @return list<string>
     */
    public function missingRequirements(): array
    {
        $missing = array_filter(
            OnboardingRequirement::cases(),
            fn (OnboardingRequirement $requirement): bool => ($this->{$requirement->value} ?? RequirementStatus::Pending) === RequirementStatus::Pending,
        );

        return array_values(array_map(fn (OnboardingRequirement $requirement): string => $requirement->label(), $missing));
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'station_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
