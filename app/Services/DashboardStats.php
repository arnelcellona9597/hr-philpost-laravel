<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\EmploymentStatus;
use App\Enums\LeaveStatus;
use App\Enums\RequirementsCompletion;
use App\Support\HrDate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the dashboard, computed with SQL GROUP BY so they stay fast and exact however many
 * employees there are. Filters are a branch and a classification.
 */
class DashboardStats
{
    private const NOT_PURSUED = [ApplicationStatus::NotHired->value, ApplicationStatus::Withdrawn->value];

    /**
     * Headcount by status, gender, classification and position. With `$asOf` it is the headcount on that
     * date from the employment history: someone is Active when a period covers the day, and otherwise
     * shows the way their latest earlier period ended. Without it, it is today's record.
     *
     * @return array{total: int, byStatus: array<string, int>, byStatusGender: list<object>, byClassification: list<object>, positions: list<array{position: string, total: int}>, asOf: string|null}
     */
    public function employees(?int $branchId, ?string $classification, ?string $asOf = null): array
    {
        $asOf = $asOf !== null && $asOf < HrDate::today() ? $asOf : null;
        [$status, $class, $position] = $asOf === null
            ? ['e.employment_status', 'e.classification', 'e.position']
            : $this->historicColumns($asOf);
        $base = fn (): Builder => $asOf === null ? $this->employeeQuery($branchId, $classification) : $this->historicQuery($asOf, $branchId, $classification, $class);

        $positions = $base()
            ->whereRaw("{$status} = ?", [EmploymentStatus::Active->value])
            ->selectRaw("COALESCE(NULLIF(TRIM({$position}), ''), '(No position)') AS position, COUNT(*) AS total")
            ->groupBy('position')
            ->orderByDesc('total')
            ->orderBy('position')
            ->get()
            ->map(fn (object $row): array => ['position' => $row->position, 'total' => (int) $row->total])
            ->all();

        return [
            'total' => $base()->count(),
            'byStatus' => $base()->selectRaw("{$status} AS status, COUNT(*) AS total")->groupBy('status')->pluck('total', 'status')->map(fn (mixed $total): int => (int) $total)->all(),
            'byStatusGender' => $base()->selectRaw("{$status} AS status, e.gender AS gender, COUNT(*) AS total")->groupBy('status', 'gender')->get()->all(),
            'byClassification' => $base()->selectRaw("{$class} AS classification, {$status} AS status, COUNT(*) AS total")->groupBy('classification', 'status')->get()->all(),
            'positions' => $positions,
            'asOf' => $asOf,
        ];
    }

    /**
     * Hires, separations, leave and onboarding within a period and the one before it.
     *
     * @param  array{from: string, to: string, prevFrom: string, prevTo: string}  $period
     * @return array<string, mixed>
     */
    public function period(array $period, string $sparkFrom, ?int $branchId, ?string $classification): array
    {
        $employees = fn (): Builder => $this->employeeQuery($branchId, $classification);
        // Hires and separations are events of employment periods, so someone hired again counts as a hire.
        $periods = fn (): Builder => DB::table('employment_periods as p')
            ->join('employees as e', 'e.id', '=', 'p.employee_id')
            ->when($branchId, fn (Builder $query) => $query->where('e.station_id', $branchId))
            ->when($classification, fn (Builder $query) => $query->where('e.classification', $classification));
        $separated = fn (): Builder => $periods()->whereNotNull('p.separated_on');
        $leave = fn (): Builder => $employees()->join('wellness_leaves as w', 'w.employee_id', '=', 'e.id');
        $counted = fn (): Builder => $leave()->whereIn('w.status', [LeaveStatus::Approved->value, LeaveStatus::Pending->value]);
        $applications = fn (): Builder => DB::table('applications as a')->when($branchId, fn (Builder $query) => $query->where('a.station_id', $branchId));

        $byDay = fn (Builder $query, string $column): array => $query
            ->whereBetween($column, [$period['from'], $period['to']])
            ->selectRaw("DATE({$column}) AS day, COUNT(*) AS total")
            ->groupBy('day')
            ->pluck('total', 'day')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        $byMonth = fn (Builder $query, string $column): array => $query
            ->whereBetween($column, [$sparkFrom, $period['to']])
            ->selectRaw("DATE_FORMAT({$column}, '%Y-%m') AS month, COUNT(*) AS total")
            ->groupBy('month')
            ->pluck('total', 'month')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        return [
            'hiresByDay' => $byDay($periods(), 'p.hired_on'),
            'separationsByDay' => $byDay($separated(), 'p.separated_on'),
            'prevHires' => $periods()->whereBetween('p.hired_on', [$period['prevFrom'], $period['prevTo']])->count(),
            'prevSeparations' => $separated()->whereBetween('p.separated_on', [$period['prevFrom'], $period['prevTo']])->count(),
            'hiresByMonth' => $byMonth($periods(), 'p.hired_on'),
            'separationsByMonth' => $byMonth($separated(), 'p.separated_on'),
            'leaveFilings' => $counted()->whereBetween('w.inclusive_date_from', [$period['from'], $period['to']])->count(),
            'leaveDays' => (int) $counted()->whereBetween('w.inclusive_date_from', [$period['from'], $period['to']])->sum('w.days'),
            'prevLeaveDays' => (int) $counted()->whereBetween('w.inclusive_date_from', [$period['prevFrom'], $period['prevTo']])->sum('w.days'),
            'pendingLeave' => $leave()->where('w.status', LeaveStatus::Pending->value)->count(),
            'pipeline' => $applications()->selectRaw('a.application_status AS status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn (mixed $total): int => (int) $total)->all(),
            'incompleteRequirements' => $applications()->where('a.requirements_status', RequirementsCompletion::Incomplete->value)->whereNotIn('a.application_status', self::NOT_PURSUED)->count(),
        ];
    }

    /**
     * SQL for status, classification and position on a past date, read from the period that was current then.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function historicColumns(string $asOf): array
    {
        $date = DB::getPdo()->quote($asOf);

        return [
            "(CASE WHEN p.separated_on IS NULL OR p.separated_on >= {$date} THEN 'Active' ELSE COALESCE(p.separation_type, 'Resigned') END)",
            'COALESCE(p.classification, e.classification)',
            'COALESCE(p.position, e.position)',
        ];
    }

    /**
     * One row per person who had started by `$asOf`: their latest period starting on or before that day.
     */
    private function historicQuery(string $asOf, ?int $branchId, ?string $classification, string $classColumn): Builder
    {
        return DB::table('employment_periods as p')
            ->join('employees as e', 'e.id', '=', 'p.employee_id')
            ->whereRaw('p.hired_on = (SELECT MAX(p2.hired_on) FROM employment_periods p2 WHERE p2.employee_id = p.employee_id AND p2.hired_on <= ?)', [$asOf])
            ->when($branchId, fn (Builder $query) => $query->whereRaw('COALESCE(p.station_id, e.station_id) = ?', [$branchId]))
            ->when($classification, fn (Builder $query) => $query->whereRaw("{$classColumn} = ?", [$classification]));
    }

    private function employeeQuery(?int $branchId, ?string $classification): Builder
    {
        return DB::table('employees as e')
            ->when($branchId, fn (Builder $query) => $query->where('e.station_id', $branchId))
            ->when($classification, fn (Builder $query) => $query->where('e.classification', $classification));
    }
}
