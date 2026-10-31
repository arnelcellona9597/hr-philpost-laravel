<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\MessageCategory;
use App\Exceptions\UserFacingException;
use App\Models\Application;
use App\Models\Employee;
use App\Models\Message;
use App\Models\NotificationSetting;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Onboarding: the "requirements for employment" email and converting an applicant to an employee.
 */
class ApplicationService
{
    public function __construct(protected MessageQueue $queue) {}

    /**
     * Save an applicant, then email the requirements automatically when that is due.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(Application $application, array $data, ?User $actor): Application
    {
        $wasCreated = ! $application->exists;
        $previous = $wasCreated ? null : ['status' => $application->application_status, 'email' => $application->email, 'employee_id' => $application->employee_id];

        $application->fill($data)->save();

        $this->sendIfDue($application, $previous, $actor);

        return $application;
    }

    /**
     * Add the employee and link the applicant in one transaction, so neither can exist without the other.
     *
     * @param  array<string, mixed>  $employeeData
     */
    public function convert(Application $application, array $employeeData, ?User $actor): Employee
    {
        $previous = ['status' => $application->application_status, 'email' => $application->email, 'employee_id' => $application->employee_id];

        $employee = DB::transaction(function () use ($application, $employeeData): Employee {
            $employee = Employee::query()->create($employeeData);
            $application->fill(['employee_id' => $employee->id, 'application_status' => ApplicationStatus::Hired])->save();

            return $employee;
        });

        $this->sendIfDue($application, $previous, $actor);

        return $employee;
    }

    /**
     * The still-needed requirements as one bullet per line.
     */
    public function requirementsList(Application $application): string
    {
        $missing = $application->missingRequirements();

        return $missing === [] ? 'All requirements are already complete — thank you.' : implode("\n", array_map(fn (string $label): string => "• {$label}", $missing));
    }

    /**
     * Where the email goes: the applicant's address, else the linked employee's.
     */
    public function emailAddress(Application $application): ?string
    {
        $address = trim((string) ($application->email ?: $application->employee?->email));

        return $address === '' ? null : Str::lower($address);
    }

    /**
     * Queue the email and record when and where it went.
     *
     * @throws UserFacingException when there is no address to send to
     */
    public function sendRequirements(Application $application, ?User $actor, bool $manual = false): string
    {
        $to = $this->emailAddress($application)
            ?? throw new UserFacingException("No email address: add the applicant's email (or link an employee with one) first.");

        $name = trim($application->applicant_name);

        $this->queue->queueOne(
            $this->queue->template('hired-requirements', [
                'subject' => 'Requirements for employment',
                'body' => "Hi {{firstName}},\n\nPlease submit the following requirements to the HR office:\n\n{{requirementsList}}",
            ]),
            MessageCategory::Onboarding,
            ['email' => $to, 'name' => $name, 'employee' => $application->employee],
            [
                'firstName' => Str::before($name, ' '),
                'fullName' => $name,
                'position' => $application->position_applied,
                'station' => $application->station?->name ?: (SiteSetting::current()->company_name ?: 'our office'),
                'requirementsList' => $this->requirementsList($application),
            ],
            $manual ? 'Applicant' : 'Applicant (automatic)',
            ['collection' => 'applications', 'id' => $application->id],
            $actor,
            ! $manual,
        );

        $application->forceFill(['requirements_email_sent_at' => now(), 'requirements_email_to' => $to])->save();

        return $to;
    }

    /**
     * The newest outbox entry for this applicant.
     */
    public function lastMessage(Application $application): ?Message
    {
        return Message::query()
            ->where('related_collection', 'applications')
            ->where('related_id', (string) $application->id)
            ->latest('id')
            ->first();
    }

    /**
     * Once per hire: when the applicant becomes Hired, or gains an address after being hired.
     * A notification problem must never block saving the applicant.
     *
     * @param  array{status: ApplicationStatus|null, email: string|null, employee_id: int|null}|null  $previous
     */
    private function sendIfDue(Application $application, ?array $previous, ?User $actor): void
    {
        if ($application->application_status !== ApplicationStatus::Hired || $application->requirements_email_sent_at !== null) {
            return;
        }

        $becameHired = $previous === null || $previous['status'] !== ApplicationStatus::Hired;
        $gainedAddress = ($application->email && ! ($previous['email'] ?? null)) || ($application->employee_id && ! ($previous['employee_id'] ?? null));

        if (! $becameHired && ! $gainedAddress) {
            return;
        }

        try {
            if (NotificationSetting::current()->hired_requirements_to_applicant && $this->emailAddress($application) !== null) {
                $this->sendRequirements($application, $actor);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
