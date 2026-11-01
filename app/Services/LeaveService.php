<?php

namespace App\Services;

use App\Enums\LeaveStatus;
use App\Enums\MessageCategory;
use App\Enums\UserStatus;
use App\Exceptions\UserFacingException;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveSetting;
use App\Models\Notification;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Models\WellnessLeave;
use App\Support\HrDate;
use App\Support\Workdays;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Wellness leave rules: working days, yearly allowance, overlaps and each filing's running balance.
 */
class LeaveService
{
    public const COUNTED = [LeaveStatus::Approved, LeaveStatus::Pending];

    public function __construct(protected MessageQueue $queue) {}

    /**
     * Count a filing's working days and make sure no year's allowance is exceeded and that it
     * doesn't overlap another filing. Used by saving and by the live preview.
     *
     * @return array{ok: bool, error?: string, days: int, byYear: array<int, int>, years: list<array{year: int, allowance: int, usedBefore: int, thisFiling: int, remaining: int}>}
     */
    public function check(int $employeeId, ?string $from, ?string $to, ?LeaveStatus $status = null, ?int $excludeId = null): array
    {
        $from = HrDate::toYmd($from);
        $to = HrDate::toYmd($to);

        if ($from === null || $to === null) {
            return $this->rejected('Enter valid inclusive dates.');
        }

        if ($to < $from) {
            return $this->rejected('The inclusive end date is before the start date.');
        }

        try {
            $count = Workdays::count($from, $to, Holiday::query()->whereBetween('date', [$from, $to])->toBase()->pluck('date'));
        } catch (InvalidArgumentException $exception) {
            return $this->rejected($exception->getMessage());
        }

        if ($count['total'] === 0) {
            return $this->rejected('The selected dates contain no working days (weekends/holidays only).');
        }

        $years = array_keys($count['byYear']);
        $settings = LeaveSetting::current();
        $others = WellnessLeave::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', self::COUNTED)
            ->where('inclusive_date_from', '<=', max($years).'-12-31')
            ->where('inclusive_date_to', '>=', min($years).'-01-01')
            ->when($excludeId, fn ($query) => $query->whereKeyNot($excludeId))
            ->get();

        $counted = $status === null || $status->consumesAllowance();

        $summary = array_map(function (int $year) use ($count, $counted, $others, $settings): array {
            $allowance = $settings->allowanceForYear($year);
            $usedBefore = $others->sum(fn (WellnessLeave $leave): int => (int) ($leave->days_by_year[$year] ?? 0));
            $thisFiling = $counted ? $count['byYear'][$year] : 0;

            return ['year' => $year, 'allowance' => $allowance, 'usedBefore' => $usedBefore, 'thisFiling' => $thisFiling, 'remaining' => $allowance - $usedBefore - $thisFiling];
        }, $years);

        $result = ['days' => $count['total'], 'byYear' => $count['byYear'], 'years' => $summary];

        $overlap = $others->first(fn (WellnessLeave $leave): bool => $leave->inclusive_date_from->toDateString() <= $to && $leave->inclusive_date_to->toDateString() >= $from);

        if ($counted && $overlap !== null) {
            return ['ok' => false, 'error' => "These dates overlap another filing ({$overlap->inclusive_date_from->toDateString()} to {$overlap->inclusive_date_to->toDateString()}).", ...$result];
        }

        foreach ($summary as $year) {
            if ($year['remaining'] < 0) {
                $left = max(0, $year['allowance'] - $year['usedBefore']);

                return ['ok' => false, 'error' => "Exceeds the {$year['year']} wellness leave allowance: {$left} of {$year['allowance']} day(s) left, this filing needs {$year['thisFiling']}.", ...$result];
            }
        }

        return ['ok' => true, ...$result];
    }

    /**
     * Save a filing. The employee row is locked so two parallel filings can't both pass the allowance check.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws UserFacingException when the filing breaks a leave rule
     */
    public function save(WellnessLeave $leave, array $data, ?User $actor, bool $notify = true): WellnessLeave
    {
        $wasCreated = ! $leave->exists;
        $previousStatus = $leave->status;

        DB::transaction(function () use ($leave, $data): void {
            $leave->fill($data);

            $employee = Employee::query()->whereKey($leave->employee_id)->lockForUpdate()->first()
                ?? throw new UserFacingException('Select an employee.');

            $result = $this->check($employee->id, $leave->inclusive_date_from?->toDateString(), $leave->inclusive_date_to?->toDateString(), $leave->status, $leave->id);

            if (! $result['ok']) {
                throw new UserFacingException($result['error']);
            }

            $leave->fill([
                'last_name' => $employee->last_name,
                'first_name' => $employee->first_name,
                'middle_name' => $employee->middle_name,
                'days' => $result['days'],
                'days_by_year' => $result['byYear'],
                'year' => (int) $leave->inclusive_date_from->format('Y'),
            ])->save();
        });

        if (! $notify) {
            return $leave;
        }

        if ($wasCreated) {
            $this->notify($leave, 'filed', $actor);
            $this->alertStaff($leave, $actor);
        } elseif ($previousStatus !== $leave->status) {
            $this->notify($leave, 'status', $actor);
        }

        return $leave;
    }

    /**
     * Remaining days after each filing, in its starting year, counting filings in date order. Computed
     * on read so it stays right when earlier filings are edited or cancelled.
     *
     * @param  Collection<int, WellnessLeave>  $rows
     * @return array<int, int>
     */
    public function remainingAfter(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $settings = LeaveSetting::current();
        $years = $rows->map(fn (WellnessLeave $leave): int => (int) $leave->inclusive_date_from->format('Y'));
        $ledger = WellnessLeave::query()
            ->whereIn('employee_id', $rows->pluck('employee_id')->unique())
            ->whereIn('status', self::COUNTED)
            ->where('inclusive_date_from', '<=', $years->max().'-12-31')
            ->where('inclusive_date_to', '>=', ($years->min() - 1).'-01-01')
            ->orderBy('inclusive_date_from')
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id');

        $remaining = [];

        foreach ($rows as $row) {
            $year = (int) $row->inclusive_date_from->format('Y');
            $start = $row->inclusive_date_from->toDateString();
            $used = 0;

            foreach ($ledger->get($row->employee_id, []) as $entry) {
                $entryStart = $entry->inclusive_date_from->toDateString();

                if ($entryStart > $start || ($entryStart === $start && $entry->id > $row->id)) {
                    break;
                }

                $used += (int) ($entry->days_by_year[$year] ?? 0);
            }

            // A cancelled or disapproved filing shows the balance as of its date without consuming days.
            $remaining[$row->id] = $settings->allowanceForYear($year) - $used;
        }

        return $remaining;
    }

    /**
     * Days an employee has used in a year.
     */
    public function usedInYear(Employee $employee, int $year): int
    {
        return $employee->wellnessDaysUsedIn($year);
    }

    /**
     * @return array{byMonth: array<int, array{filings: int, days: int}>, byStatus: array<string, int>, topUsers: list<array{name: string, days: int}>, employeesWithLeave: int}
     */
    public function stats(int $year): array
    {
        $counted = array_map(fn (LeaveStatus $status): string => $status->value, self::COUNTED);
        $yearPath = '$."'.$year.'"';
        $yearDays = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(w.days_by_year, ?)) AS UNSIGNED)';

        $byMonth = DB::table('wellness_leaves')
            ->selectRaw('MONTH(inclusive_date_from) AS month, COUNT(*) AS filings, SUM(days) AS days')
            ->whereYear('inclusive_date_from', $year)
            ->whereIn('status', $counted)
            ->groupByRaw('MONTH(inclusive_date_from)')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->month => ['filings' => (int) $row->filings, 'days' => (int) $row->days]])
            ->all();

        $byStatus = DB::table('wellness_leaves')
            ->selectRaw('status, COUNT(*) AS total')
            ->whereYear('inclusive_date_from', $year)
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        $charged = DB::table('wellness_leaves as w')
            ->join('employees as e', 'e.id', '=', 'w.employee_id')
            ->whereIn('w.status', $counted)
            ->whereRaw('JSON_EXTRACT(w.days_by_year, ?) IS NOT NULL', [$yearPath]);

        $topUsers = (clone $charged)
            ->selectRaw("e.full_name AS name, SUM({$yearDays}) AS days", [$yearPath])
            ->groupBy('w.employee_id', 'e.full_name')
            ->orderByDesc('days')
            ->orderBy('name')
            ->limit(15)
            ->get()
            ->map(fn (object $row): array => ['name' => $row->name, 'days' => (int) $row->days])
            ->all();

        return [
            'byMonth' => $byMonth,
            'byStatus' => $byStatus,
            'topUsers' => $topUsers,
            'employeesWithLeave' => (clone $charged)->distinct()->count('w.employee_id'),
        ];
    }

    /**
     * A notification problem must never block saving the leave.
     */
    private function notify(WellnessLeave $leave, string $kind, ?User $actor): void
    {
        try {
            $settings = NotificationSetting::current();
            $enabled = $kind === 'filed' ? $settings->leave_filed_to_employee : $settings->leave_status_to_employee;

            if (! $enabled) {
                return;
            }

            $from = $leave->inclusive_date_from->toDateString();
            $to = $leave->inclusive_date_to->toDateString();
            $year = $leave->inclusive_date_from->year;

            $this->queue->queueOne(
                $this->queue->template($kind === 'filed' ? 'leave-filed' : 'leave-status', [
                    'subject' => 'Wellness leave: {{leaveDates}}',
                    'body' => "Hi {{firstName}},\n\nYour wellness leave on {{leaveDates}} is {{leaveStatus}}.",
                ]),
                MessageCategory::Leave,
                ['employee' => $leave->employee],
                [
                    'leaveDates' => $from === $to ? HrDate::format($from) : HrDate::format($from).' to '.HrDate::format($to),
                    'leaveDays' => $leave->days,
                    'leaveStatus' => $leave->status->value,
                    'leaveRemaining' => LeaveSetting::current()->allowanceForYear($year) - $leave->employee->wellnessDaysUsedIn($year),
                ],
                'Employee (automatic)',
                ['collection' => 'wellness-leaves', 'id' => $leave->id],
                $actor,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Tell the other HR users a filing came in.
     */
    private function alertStaff(WellnessLeave $leave, ?User $actor): void
    {
        $staff = User::query()->where('status', UserStatus::Approved)->when($actor, fn ($query) => $query->whereKeyNot($actor->id))->pluck('id');

        foreach ($staff as $userId) {
            Notification::query()->create([
                'user_id' => $userId,
                'title' => 'Wellness leave filed',
                'body' => "{$leave->first_name} {$leave->last_name}: {$leave->days} day".($leave->days === 1 ? '' : 's').' ('.$leave->status->value.')',
                'link' => "/leave/{$leave->id}",
            ]);
        }
    }

    /**
     * @return array{ok: false, error: string, days: 0, byYear: array{}, years: array{}}
     */
    private function rejected(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'days' => 0, 'byYear' => [], 'years' => []];
    }
}
