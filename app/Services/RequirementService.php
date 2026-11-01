<?php

namespace App\Services;

use App\Enums\EmploymentStatus;
use App\Enums\IpcrPeriod;
use App\Models\ComplianceRecord;
use App\Models\Employee;
use App\Models\IpcrRating;
use App\Models\RequirementDeadlineSetting;
use App\Support\Deadlines;
use App\Support\HrDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Compliance and timeliness figures for the annual requirements, among currently Active employees.
 */
class RequirementService
{
    /**
     * "Complied" means the employee has a record with a Date Submitted. For IPCR, one period is enough.
     *
     * @param  class-string<ComplianceRecord>  $model
     * @param  array{branch?: int|null, classification?: string|null}  $filter
     * @return array{active: int, complied: int, missing: int, missingIds: list<int>}
     */
    public function compliance(string $model, int $year, array $filter = []): array
    {
        $rows = DB::table('employees as e')
            ->leftJoin((new $model)->getTable().' as r', function ($join) use ($year): void {
                $join->on('r.employee_id', '=', 'e.id')->where('r.year', '=', $year);
            })
            ->where('e.employment_status', EmploymentStatus::Active->value)
            ->when($filter['branch'] ?? null, fn ($query, int $branch) => $query->where('e.station_id', $branch))
            ->when($filter['classification'] ?? null, fn ($query, string $classification) => $query->where('e.classification', $classification))
            ->groupBy('e.id')
            ->selectRaw('e.id, MAX(r.date_submitted IS NOT NULL) AS complied')
            ->get();

        $complied = $rows->filter(fn (object $row): bool => (int) $row->complied === 1)->count();
        $missing = $rows->reject(fn (object $row): bool => (int) $row->complied === 1)->pluck('id')->map(intval(...))->values()->all();

        return ['active' => $rows->count(), 'complied' => $complied, 'missing' => $rows->count() - $complied, 'missingIds' => $missing];
    }

    /**
     * On time / Late counts, classified by the same rules as the list badges. IPCR counts per period.
     *
     * @param  class-string<ComplianceRecord>  $model
     * @param  array{branch?: int|null, classification?: string|null}  $filter
     * @return array{counts: array<string, int>, ids: array<string, list<int>>, deadlines: list<array{period: string|null, deadline: string|null, source: string}>}
     */
    public function timeliness(string $model, int $year, array $filter = []): array
    {
        $isIpcr = $model === IpcrRating::class;
        $settings = RequirementDeadlineSetting::current()->toArray();

        $rows = DB::table('employees as e')
            ->leftJoin((new $model)->getTable().' as r', function ($join) use ($year): void {
                $join->on('r.employee_id', '=', 'e.id')->where('r.year', '=', $year);
            })
            ->where('e.employment_status', EmploymentStatus::Active->value)
            ->when($filter['branch'] ?? null, fn ($query, int $branch) => $query->where('e.station_id', $branch))
            ->when($filter['classification'] ?? null, fn ($query, string $classification) => $query->where('e.classification', $classification))
            ->select('e.id', 'r.date_submitted')
            ->when($isIpcr, fn ($query) => $query->addSelect('r.rating_period'))
            ->get();

        $submittedBy = [];

        foreach ($rows as $row) {
            if ($row->date_submitted !== null) {
                $submittedBy[$row->id.'|'.($row->rating_period ?? '')] = $row->date_submitted;
            }
        }

        $deadlines = $this->deadlines($model, $year, $settings);
        $statuses = ['on-time', 'late', 'submitted', 'overdue', 'pending'];
        $counts = array_fill_keys($statuses, 0);
        $ids = array_fill_keys($statuses, []);

        foreach ($rows->pluck('id')->unique() as $id) {
            foreach ($deadlines as $deadline) {
                $status = Deadlines::timeliness($submittedBy[$id.'|'.($deadline['period'] ?? '')] ?? null, $deadline['deadline'])['status'];
                $counts[$status]++;
                $ids[$status][$id] = (int) $id;
            }
        }

        return ['counts' => $counts, 'ids' => array_map(array_values(...), $ids), 'deadlines' => $deadlines];
    }

    /**
     * Each deadline of the requirement for a year, one per IPCR period.
     *
     * @param  class-string<ComplianceRecord>  $model
     * @param  array<string, mixed>|null  $settings
     * @return list<array{period: string|null, deadline: string|null, source: string}>
     */
    public function deadlines(string $model, int $year, ?array $settings = null): array
    {
        $settings ??= RequirementDeadlineSetting::current()->toArray();
        $periods = $model === IpcrRating::class ? array_column(IpcrPeriod::cases(), 'value') : [null];

        return array_map(fn (?string $period): array => [
            'period' => $period,
            'deadline' => Deadlines::for($settings, $model::SLUG, $year, $period),
            'source' => Deadlines::source($settings, $model::SLUG, $year, $period),
        ], $periods);
    }

    /**
     * Limit records to one timeliness status, with the same rules as the badges.
     *
     * @param  Builder<ComplianceRecord>  $query
     * @param  list<array{period: string|null, deadline: string|null, source: string}>  $deadlines
     */
    public function filterByStatus(Builder $query, string $status, array $deadlines): void
    {
        $today = HrDate::today();

        $conditions = array_filter(array_map(function (array $deadline) use ($status, $today): ?array {
            $due = $deadline['deadline'];
            $passed = $due !== null && $today > $due;

            $condition = match ($status) {
                'on-time' => $due ? [['date_submitted', '<=', $due]] : null,
                'late' => $due ? [['date_submitted', '>', $due]] : null,
                'submitted' => $due ? null : 'submitted',
                'overdue' => $passed ? 'blank' : null,
                'pending' => $passed ? null : 'blank',
                default => null,
            };

            return $condition === null ? null : ['period' => $deadline['period'], 'condition' => $condition];
        }, $deadlines));

        if ($conditions === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $any) use ($conditions): void {
            foreach ($conditions as ['period' => $period, 'condition' => $condition]) {
                $any->orWhere(function (Builder $part) use ($period, $condition): void {
                    $part->when($period, fn (Builder $scoped) => $scoped->where('rating_period', $period));

                    match ($condition) {
                        'blank' => $part->whereNull('date_submitted'),
                        'submitted' => $part->whereNotNull('date_submitted'),
                        default => $part->where($condition),
                    };
                });
            }
        });
    }

    /**
     * Blank rows for every active employee who has none yet this year. Returns how many were added.
     *
     * @param  class-string<ComplianceRecord>  $model
     */
    public function generateRows(string $model, int $year, ?string $period): int
    {
        return DB::transaction(function () use ($model, $year, $period): int {
            $existing = $model::query()->where('year', $year)->when($period, fn (Builder $query) => $query->where('rating_period', $period))->pluck('employee_id');

            $employees = Employee::query()
                ->where('employment_status', EmploymentStatus::Active)
                ->whereNotIn('id', $existing)
                ->get();

            foreach ($employees as $employee) {
                $model::query()->create(['employee_id' => $employee->id, 'year' => $year, ...($period ? ['rating_period' => $period] : [])]);
            }

            return $employees->count();
        });
    }
}
