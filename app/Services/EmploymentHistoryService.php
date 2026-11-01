<?php

namespace App\Services;

use App\Enums\EmploymentStatus;
use App\Exceptions\UserFacingException;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Support\HrDate;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the history of when each person was employed. A person can leave and be hired again any
 * number of times; periods never overlap and only the latest one can be open. The employee's own
 * hire date, last day and status mirror the latest period, so every page that reads them keeps working.
 *
 * @phpstan-type PeriodRow array{number: int, period: EmploymentPeriod, length: array{years: int, months: int, days: int}|null, gap: array{years: int, months: int, days: int}|null}
 */
class EmploymentHistoryService
{
    /**
     * After an employee is saved, make the latest period say the same. The first save creates it.
     */
    public function syncPeriodFromEmployee(Employee $employee): void
    {
        if ($employee->date_hired === null) {
            return;
        }

        $status = $employee->employment_status ?? EmploymentStatus::Active;
        $separated = $status !== EmploymentStatus::Active;
        $values = [
            'hired_on' => $employee->date_hired->toDateString(),
            'separated_on' => $separated ? $employee->last_day_of_service?->toDateString() : null,
            'separation_type' => $separated ? $status : null,
            'position' => $employee->position,
            'station_id' => $employee->station_id,
            'classification' => $employee->classification,
        ];

        $latest = $employee->employmentPeriods()->reorder('hired_on', 'desc')->first();

        if ($latest === null) {
            $period = new EmploymentPeriod($values);
            $period->employee_id = $employee->id;
            $period->saveQuietly();

            return;
        }

        $latest->fill($values);

        if ($latest->isDirty()) {
            $latest->saveQuietly();
        }
    }

    /**
     * After a period changes, copy the latest one onto the employee's own record.
     */
    public function syncEmployeeFromLatest(?Employee $employee): void
    {
        $latest = $employee?->employmentPeriods()->reorder('hired_on', 'desc')->first();

        if ($latest === null) {
            return;
        }

        $employee->forceFill([
            'date_hired' => $latest->hired_on,
            'last_day_of_service' => $latest->separated_on,
            'employment_status' => $latest->separation_type ?? EmploymentStatus::Active,
        ]);

        if ($employee->isDirty()) {
            $employee->saveQuietly();
        }
    }

    /**
     * Why these dates cannot be a period for this employee, or null when they can.
     */
    public function problem(Employee $employee, ?EmploymentPeriod $period, ?string $hiredOn, ?string $separatedOn): ?string
    {
        if ($hiredOn === null || ! HrDate::isValid($hiredOn)) {
            return 'Enter the date hired.';
        }

        if ($separatedOn !== null && ! HrDate::isValid($separatedOn)) {
            return 'Enter a valid date separated.';
        }

        if ($separatedOn !== null && $separatedOn < $hiredOn) {
            return 'The date separated cannot be before the date hired.';
        }

        $others = $employee->employmentPeriods()->when($period?->exists, fn ($query) => $query->whereKeyNot($period->id))->get();

        foreach ($others as $other) {
            $otherEnd = $other->separated_on?->toDateString();

            if (($otherEnd === null || $hiredOn <= $otherEnd) && ($separatedOn === null || $separatedOn >= $other->hired_on->toDateString())) {
                return 'These dates overlap the period from '.HrDate::format($other->hired_on).' to '.($otherEnd ? HrDate::format($otherEnd) : 'present').'.';
            }
        }

        return null;
    }

    /**
     * Problems with the hire and last-day values about to be saved on an employee, keyed by field.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public function employeeProblems(?Employee $employee, array $data): array
    {
        if ($employee === null || ! $employee->exists) {
            return [];
        }

        $hired = array_key_exists('date_hired', $data) ? $data['date_hired'] : $employee->date_hired?->toDateString();

        if ($hired === null) {
            return [];
        }

        $status = (string) ($data['employment_status'] ?? $employee->employment_status?->value ?? 'Active');
        $last = array_key_exists('last_day_of_service', $data) ? $data['last_day_of_service'] : $employee->last_day_of_service?->toDateString();
        $latest = $employee->employmentPeriods()->reorder('hired_on', 'desc')->first();
        $message = $this->problem($employee, $latest, (string) $hired, $status === EmploymentStatus::Active->value ? null : $last);

        return $message === null ? [] : ['date_hired' => $message];
    }

    /**
     * Hire a separated employee again. The previous period must have ended; when it has no end date
     * yet, give it one.
     *
     * @param  array{position?: string|null, station_id?: int|null, classification?: string|null, remarks?: string|null}  $details
     *
     * @throws UserFacingException when the person is still employed or the dates clash
     */
    public function rehire(Employee $employee, string $hiredOn, ?string $previousSeparatedOn, array $details = []): EmploymentPeriod
    {
        return DB::transaction(function () use ($employee, $hiredOn, $previousSeparatedOn, $details): EmploymentPeriod {
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $latest = $locked->employmentPeriods()->reorder('hired_on', 'desc')->first()
                ?? throw new UserFacingException('This employee has no employment history yet. Set the date hired first.');

            if ($latest->isOpen()) {
                throw new UserFacingException('This employee is still employed. Record the separation before hiring them again.');
            }

            if ($latest->separated_on === null) {
                if ($previousSeparatedOn === null || ! HrDate::isValid($previousSeparatedOn)) {
                    throw new UserFacingException('Enter the last day of the previous period.');
                }

                $this->assertOk($this->problem($locked, $latest, $latest->hired_on->toDateString(), $previousSeparatedOn));
                $latest->forceFill(['separated_on' => $previousSeparatedOn])->save();
            }

            $this->assertOk($this->problem($locked, null, $hiredOn, null));

            $period = new EmploymentPeriod([
                'hired_on' => $hiredOn,
                'position' => $details['position'] ?? $locked->position,
                'station_id' => $details['station_id'] ?? $locked->station_id,
                'classification' => $details['classification'] ?? $locked->classification?->value,
                'remarks' => $details['remarks'] ?? null,
            ]);
            $period->employee_id = $locked->id;
            $period->save();

            $locked->refresh()->forceFill([
                'position' => $period->position,
                'station_id' => $period->station_id,
                'classification' => $period->classification,
            ])->save();

            return $period;
        });
    }

    /**
     * End the open period.
     *
     * @throws UserFacingException when the person is not employed or the date is wrong
     */
    public function separate(Employee $employee, string $separatedOn, EmploymentStatus $type, ?string $remarks = null): EmploymentPeriod
    {
        if ($type === EmploymentStatus::Active) {
            throw new UserFacingException('Choose how the employment ended: Resigned, Retired or Terminated.');
        }

        return DB::transaction(function () use ($employee, $separatedOn, $type, $remarks): EmploymentPeriod {
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $latest = $locked->employmentPeriods()->reorder('hired_on', 'desc')->first();

            if ($latest === null || ! $latest->isOpen()) {
                throw new UserFacingException('This employee is not currently employed.');
            }

            $this->assertOk($this->problem($locked, $latest, $latest->hired_on->toDateString(), $separatedOn));

            $latest->forceFill(['separated_on' => $separatedOn, 'separation_type' => $type, 'remarks' => $remarks ?: $latest->remarks])->save();

            return $latest;
        });
    }

    /**
     * Correct a period, old or current.
     *
     * @param  array{hired_on?: string|null, separated_on?: string|null, separation_type?: string|null, position?: string|null, station_id?: int|null, classification?: string|null, remarks?: string|null}  $data
     *
     * @throws UserFacingException when the dates clash
     */
    public function update(EmploymentPeriod $period, array $data): EmploymentPeriod
    {
        return DB::transaction(function () use ($period, $data): EmploymentPeriod {
            $employee = Employee::query()->whereKey($period->employee_id)->lockForUpdate()->firstOrFail();
            $hiredOn = $data['hired_on'] ?? $period->hired_on->toDateString();
            $separatedOn = $data['separated_on'] ?? null;
            $type = EmploymentStatus::tryFrom((string) ($data['separation_type'] ?? ''));

            if ($separatedOn !== null && ($type === null || $type === EmploymentStatus::Active)) {
                throw new UserFacingException('Choose how the employment ended: Resigned, Retired or Terminated.');
            }

            $this->assertOk($this->problem($employee, $period, $hiredOn, $separatedOn));

            $period->forceFill([
                'hired_on' => $hiredOn,
                'separated_on' => $separatedOn,
                'separation_type' => $type === EmploymentStatus::Active ? null : $type,
                'position' => $data['position'] ?? null,
                'station_id' => $data['station_id'] ?? null,
                'classification' => $data['classification'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ])->save();

            return $period;
        });
    }

    /**
     * @throws UserFacingException when it is the only period
     */
    public function delete(EmploymentPeriod $period): void
    {
        DB::transaction(function () use ($period): void {
            Employee::query()->whereKey($period->employee_id)->lockForUpdate()->firstOrFail();

            if (EmploymentPeriod::query()->where('employee_id', $period->employee_id)->count() <= 1) {
                throw new UserFacingException('The only employment period cannot be deleted. Edit it instead.');
            }

            $period->delete();
        });
    }

    /**
     * The history in order, with how long each period lasted and the gap before it, and the total.
     *
     * @return array{rows: list<PeriodRow>, total: array{years: int, months: int, days: int}|null}
     */
    public function summary(Employee $employee, ?string $today = null): array
    {
        $today ??= HrDate::today();
        $rows = [];
        $previousEnd = null;

        foreach ($employee->employmentPeriods()->get() as $index => $period) {
            $hired = $period->hired_on->toDateString();
            $end = $period->separated_on?->toDateString();

            $rows[] = [
                'number' => $index + 1,
                'period' => $period,
                'length' => HrDate::lengthOfService($hired, $end, $today),
                'gap' => $previousEnd !== null ? HrDate::diff($previousEnd, $hired) : null,
            ];

            $previousEnd = $end;
        }

        $lengths = array_values(array_filter(array_column($rows, 'length')));

        return ['rows' => $rows, 'total' => $lengths === [] ? null : HrDate::sumSpans($lengths)];
    }

    /**
     * @throws UserFacingException
     */
    private function assertOk(?string $problem): void
    {
        if ($problem !== null) {
            throw new UserFacingException($problem);
        }
    }
}
