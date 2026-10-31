<?php

namespace App\Support;

use App\Enums\EmploymentStatus;
use App\Models\Branch;
use App\Models\Employee;

/**
 * Options for branch and employee pickers.
 */
class Choices
{
    /**
     * @return list<array{value: int, label: string}>
     */
    public static function branches(): array
    {
        return Branch::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Branch $branch): array => ['value' => $branch->id, 'label' => $branch->name])
            ->all();
    }

    /**
     * Active employees first, labelled "Dela Cruz, Juan P. — EMP-0001".
     *
     * @return list<array{value: int, label: string}>
     */
    public static function employees(bool $includeInactive = true): array
    {
        return Employee::query()
            ->when(! $includeInactive, fn ($query) => $query->where('employment_status', EmploymentStatus::Active))
            ->orderByRaw('employment_status <> ?', [EmploymentStatus::Active->value])
            ->orderBy('full_name')
            ->get(['id', 'employee_id', 'full_name', 'employment_status'])
            ->map(function (Employee $employee): array {
                $status = $employee->employment_status === EmploymentStatus::Active ? '' : " ({$employee->employment_status->value})";

                return ['value' => $employee->id, 'label' => "{$employee->full_name} — {$employee->employee_id}{$status}"];
            })
            ->all();
    }

    /**
     * Distinct position titles, offered as suggestions so titles stay consistent.
     *
     * @return list<string>
     */
    public static function positions(): array
    {
        return Employee::query()->whereNotNull('position')->distinct()->orderBy('position')->pluck('position')->all();
    }
}
