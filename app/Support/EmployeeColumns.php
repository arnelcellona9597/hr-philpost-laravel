<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The columns the employee table can show. Each person chooses theirs; the choice is remembered
 * in this browser and falls back to the usual columns.
 */
class EmployeeColumns
{
    public const COOKIE = 'hr-employee-columns';

    public const DEFAULT = ['employee_id', 'name', 'position', 'station', 'classification', 'status', 'date_hired', 'service'];

    /**
     * The usual columns, with the sort each one offers.
     *
     * @var array<string, array{label: string, sort: string|null}>
     */
    private const CORE = [
        'employee_id' => ['label' => 'Employee ID', 'sort' => 'employeeId'],
        'name' => ['label' => 'Name', 'sort' => 'fullName'],
        'position' => ['label' => 'Position', 'sort' => 'position'],
        'station' => ['label' => 'Station', 'sort' => null],
        'classification' => ['label' => 'Classification', 'sort' => 'classification'],
        'status' => ['label' => 'Status', 'sort' => 'employmentStatus'],
        'date_hired' => ['label' => 'Date hired', 'sort' => 'dateHired'],
        'service' => ['label' => 'Service', 'sort' => null],
    ];

    /**
     * Extra columns, taken from the employee fields by name.
     */
    private const EXTRA = [
        'Contact' => ['email', 'contact_number', 'address'],
        'Personal' => ['gender', 'date_of_birth', 'age', 'civil_status', 'religion', 'blood_type'],
        'Education' => ['educational_attainment', 'courses', 'professional_eligibility'],
        'Employment' => ['region', 'nature_of_work', 'last_day_of_service', 'remarks'],
        'Government IDs' => ['sss', 'tin', 'pagibig', 'philhealth'],
        'Bank' => ['bank_name', 'bank_account_number'],
        'Emergency contact' => ['emergency_contact_name', 'emergency_contact_number'],
    ];

    /**
     * Every column that can be chosen, by key, in the order the table shows them.
     *
     * @return array<string, array{label: string, sort: string|null, group: string, field: array<string, mixed>|null}>
     */
    public static function available(): array
    {
        $fields = collect(ImportModules::find('employees')['fields'] ?? [])->keyBy('name');
        $columns = [];

        foreach (self::CORE as $key => $column) {
            $columns[$key] = [...$column, 'group' => 'Usual', 'field' => null];
        }

        foreach (self::EXTRA as $group => $names) {
            foreach ($names as $name) {
                if ($fields->has($name)) {
                    $columns[$name] = ['label' => $fields[$name]['label'], 'sort' => null, 'group' => $group, 'field' => $fields[$name]];
                }
            }
        }

        return $columns;
    }

    /**
     * The chosen columns: the ones in the URL, else the ones remembered, else the usual set. Name is always shown.
     *
     * @return array{columns: list<string>, remember: list<string>|null|false}
     */
    public static function choose(Request $request): array
    {
        $asked = $request->query('cols');

        if ($asked === 'default') {
            return ['columns' => self::DEFAULT, 'remember' => false];
        }

        if ($asked !== null) {
            $columns = self::valid(is_array($asked) ? $asked : explode(',', (string) $asked));

            return $columns === [] ? ['columns' => self::DEFAULT, 'remember' => false] : ['columns' => $columns, 'remember' => $columns];
        }

        $saved = self::valid(explode(',', (string) $request->cookie(self::COOKIE)));

        return ['columns' => $saved === [] ? self::DEFAULT : $saved, 'remember' => null];
    }

    /**
     * @param  array<int, mixed>  $keys
     * @return list<string>
     */
    private static function valid(array $keys): array
    {
        $chosen = array_filter(array_map(fn (mixed $key): string => (string) $key, $keys));

        $known = array_keys(self::available());

        if (array_intersect($chosen, $known) === []) {
            return [];
        }

        return array_values(array_filter($known, fn (string $key): bool => $key === 'name' || in_array($key, $chosen, true)));
    }
}
