<?php

namespace App\Services;

use App\Enums\BloodType;
use App\Enums\CivilStatus;
use App\Enums\Classification;
use App\Enums\EducationalAttainment;
use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Enums\Region;
use App\Models\Branch;
use App\Models\BranchUnit;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Support\GovId;
use App\Support\StationNames;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;

/**
 * Loads the office's "COS Master File" sheet over the employees already in the system.
 *
 * People are matched by Employee ID, else by name and birth date. A value from the file replaces the stored
 * one only when it is present and valid, so a blank or unreadable cell never erases anything. Nobody is
 * deleted. The file's Station/Division/Unit text becomes the employee's unit: an existing unit with that name is used,
 * else a new unit is made with no branch yet, for HR to place. Branches are never created or changed, and a new
 * person gets a branch only when an existing one matches the text. `plan()` changes nothing; `apply()` saves a plan in one transaction.
 *
 * @phpstan-type Row array{line: int, file_status: ?string, last_name: string, first_name: string, middle_name: ?string, data: array<string, mixed>, periods: list<array{from: string, to: ?string}>, warnings: list<string>, station: string, status_hint: ?EmploymentStatus}
 */
class CosMasterImport
{
    public const SHEET = 'COS Master File';

    /**
     * Columns by their header text, normalised. The second Contact Number and Address are the emergency contact's.
     */
    private const COLUMNS = [
        'status' => 'file_status', 'photo link' => 'file_status', 'last name' => 'last_name', 'first name' => 'first_name', 'middle name' => 'middle_name', 'extn.' => 'extension',
        'gender' => 'gender', 'date of birth' => 'date_of_birth', 'civil status' => 'civil_status', 'religion' => 'religion',
        'blood type' => 'blood_type', 'professional eligibility / license' => 'professional_eligibility',
        'educational attainment' => 'educational_attainment', 'courses' => 'courses', 'year level/ earned units' => 'year_level_earned_units',
        'academic honors received' => 'academic_honors', 'employee id' => 'employee_id', 'station/division/unit' => 'station',
        'region' => 'region', 'position/designation' => 'position', 'nature of work' => 'nature_of_work',
        'driver license number' => 'driver_license_number', 'date hired' => 'date_hired', 'end1' => 'end1', 'rehire' => 'rehire1',
        'end2' => 'end2', 'rehire2' => 'rehire2', 'end3' => 'end3', 'rehire3' => 'rehire3', 'sss number' => 'sss',
        'pag-ibig number' => 'pagibig', 'tin number' => 'tin', 'philhealth number' => 'philhealth', 'lbp account' => 'bank_account_number',
        'email address' => 'email', 'emergency contact person' => 'emergency_contact_name', 'relationship' => 'emergency_relationship',
        'remark' => 'remarks',
    ];

    /**
     * Fields a person may change from the file. Everything else is left alone.
     */
    private const TEXT_FIELDS = [
        'middle_name', 'extension', 'religion', 'professional_eligibility', 'courses', 'year_level_earned_units', 'academic_honors',
        'nature_of_work', 'driver_license_number', 'address', 'contact_number', 'emergency_contact_number', 'emergency_address', 'remarks',
        'emergency_contact_name', 'emergency_relationship', 'bank_account_number',
    ];

    /**
     * @return list<Row>
     */
    public function read(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([self::SHEET]);
        $sheet = $reader->load($path)->getSheetByName(self::SHEET) ?? throw new RuntimeException('The workbook has no "'.self::SHEET.'" sheet.');
        $grid = $sheet->toArray(null, true, false, false);
        $columns = $this->columns($grid[0] ?? []);
        $rows = [];

        foreach (array_slice($grid, 1, null, true) as $index => $cells) {
            $row = $this->row($index + 1, $cells, $columns);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * What applying the rows would do, without changing anything.
     *
     * @param  list<Row>  $rows
     * @return array{items: list<array<string, mixed>>, branchesForNew: array{found: int, none: int}, units: array{existing: int, new: int}, counts: array<string, int>, missing: list<string>}
     */
    public function plan(array $rows): array
    {
        $existing = Employee::query()->get(['id', 'employee_id', 'last_name', 'first_name', 'date_of_birth']);
        $byId = $existing->keyBy(fn (Employee $employee): string => mb_strtolower($employee->employee_id));
        $byName = $existing->groupBy(fn (Employee $employee): string => $this->nameKey($employee->last_name, $employee->first_name));
        $usedIds = $existing->pluck('employee_id')->map(fn (string $id): string => mb_strtolower($id))->flip()->all();
        $claimed = [];
        $branchByLooseName = Branch::query()->get(['id', 'name'])->mapWithKeys(fn (Branch $branch): array => [StationNames::looseKey($branch->name) => $branch->id]);
        $seenIds = [];
        $temp = 0;
        $items = [];
        $branchesForNew = ['found' => 0, 'none' => 0];
        $unitsByName = BranchUnit::query()->get(['id', 'name', 'branch_id'])->groupBy(fn (BranchUnit $unit): string => mb_strtolower($unit->name));
        $unitNames = ['existing' => [], 'new' => []];
        $counts = ['update' => 0, 'new' => 0, 'skip' => 0];

        foreach ($rows as $row) {
            $employeeId = $row['data']['employee_id'] ?? null;
            $match = null;
            $how = '';
            $problem = null;

            if ($employeeId !== null && isset($byId[mb_strtolower($employeeId)])) {
                $found = $byId[mb_strtolower($employeeId)];
                $sameName = $this->nameKey($found->last_name, $found->first_name) === $this->nameKey($row['last_name'], $row['first_name']);
                $sameBirth = isset($row['data']['date_of_birth']) && $found->date_of_birth?->toDateString() === $row['data']['date_of_birth'];

                if ($sameName || $sameBirth) {
                    $match = $found;
                    $how = 'Employee ID';
                } else {
                    $problem = "Employee ID {$employeeId} already belongs to another person";
                }
            }

            if ($match === null && $problem === null) {
                $candidates = $byName->get($this->nameKey($row['last_name'], $row['first_name']), collect())->filter(fn (Employee $candidate): bool => ! isset($claimed[$candidate->id]));
                $dob = $row['data']['date_of_birth'] ?? null;
                $sameBirth = $dob === null ? collect() : $candidates->filter(fn (Employee $candidate): bool => $candidate->date_of_birth?->toDateString() === $dob);

                if ($sameBirth->count() === 1) {
                    $match = $sameBirth->first();
                    $how = 'name and birth date';
                } elseif ($sameBirth->count() > 1) {
                    $problem = 'More than one employee has this name and birth date';
                } elseif ($dob === null && $candidates->count() === 1) {
                    $match = $candidates->first();
                    $how = 'name only';
                    $row['warnings'][] = 'No birth date in the file; matched on name only';
                } elseif ($dob === null && $candidates->count() > 1) {
                    $problem = 'No birth date, and more than one employee has this name';
                }
            }

            if ($match !== null) {
                $claimed[$match->id] = true;
            }

            if ($match === null && $problem === null) {
                $problem = $this->newPersonProblem($row);
            }

            if ($match === null && $problem === null && $employeeId !== null && isset($seenIds[mb_strtolower($employeeId)])) {
                $problem = "Employee ID {$employeeId} appears twice in the file";
            }

            if ($employeeId !== null) {
                $seenIds[mb_strtolower($employeeId)] ??= true;
            }

            $generated = null;
            $branchId = null;

            if ($problem === null && $match === null) {
                $branchId = $row['station'] === '' ? null : $branchByLooseName->get(StationNames::looseKey(StationNames::canonical($row['station'])));
                $branchesForNew[$branchId === null ? 'none' : 'found']++;
            }

            if ($problem === null && $match === null && $employeeId === null) {
                while (isset($usedIds[mb_strtolower($generated = sprintf('TEMP-%04d', ++$temp))])) {
                    // Take the next number until one is free.
                }

                $usedIds[mb_strtolower($generated)] = true;
            }

            $unitKey = $row['station'] === '' || $problem !== null ? null : mb_strtolower($row['station']);

            if ($unitKey !== null) {
                $unitNames[$unitsByName->has($unitKey) ? 'existing' : 'new'][$unitKey] = true;
            }

            $action = $problem !== null ? 'skip' : ($match !== null ? 'update' : 'new');
            $counts[$action]++;
            $items[] = ['row' => $row, 'action' => $action, 'employee_id' => $match?->id, 'how' => $how, 'problem' => $problem, 'branch_id' => $branchId, 'unit_key' => $unitKey, 'generated_id' => $generated];
        }

        $missing = $existing->reject(fn (Employee $employee): bool => isset($claimed[$employee->id]))->map(fn (Employee $employee): string => $employee->employee_id)->values()->all();

        return ['items' => $items, 'branchesForNew' => $branchesForNew, 'units' => ['existing' => count($unitNames['existing']), 'new' => count($unitNames['new'])], 'counts' => $counts, 'missing' => $missing];
    }

    /**
     * Save a plan: new branches, new and updated employees and their employment periods, in one transaction.
     *
     * @param  array{items: list<array<string, mixed>>, branchesForNew: array{found: int, none: int}, units: array{existing: int, new: int}, counts: array<string, int>, missing: list<string>}  $plan
     * @return array{created: int, updated: int, periods: int, units: int}
     */
    public function apply(array $plan): array
    {
        $result = ['created' => 0, 'updated' => 0, 'periods' => 0, 'units' => 0];

        DB::transaction(function () use ($plan, &$result): void {
            (function () use ($plan, &$result): void {
                $units = BranchUnit::query()->get(['id', 'name', 'branch_id'])->groupBy(fn (BranchUnit $unit): string => mb_strtolower($unit->name))->map(fn ($group): int => $group->first()->id)->all();

                foreach ($plan['items'] as $item) {
                    if ($item['action'] === 'skip') {
                        continue;
                    }

                    $row = $item['row'];
                    $data = $row['data'];
                    $periods = $row['periods'];
                    $status = $row['status_hint'];

                    if ($periods !== []) {
                        $last = $periods[array_key_last($periods)];
                        $data['date_hired'] = $last['from'];
                        $data['last_day_of_service'] = $last['to'];
                        $data['employment_status'] = ($last['to'] === null ? EmploymentStatus::Active : ($status ?? EmploymentStatus::Resigned))->value;
                    }

                    $data = array_filter($data, fn (mixed $value): bool => $value !== null && $value !== '');

                    if ($item['unit_key'] !== null) {
                        if (! isset($units[$item['unit_key']])) {
                            $units[$item['unit_key']] = BranchUnit::query()->create(['name' => $row['station']])->id;
                            $result['units']++;
                        }

                        $data['unit_id'] = $units[$item['unit_key']];
                    }

                    if ($item['action'] === 'new' && $item['branch_id'] !== null) {
                        $data['station_id'] = $item['branch_id'];
                    }

                    if ($item['action'] === 'new') {
                        $employee = new Employee($data);
                        $employee->employee_id = $data['employee_id'] ?? $item['generated_id'];
                        $employee->last_name = $row['last_name'];
                        $employee->first_name = $row['first_name'];
                        $employee->classification = Classification::Cos;
                        $employee->employment_status = EmploymentStatus::tryFrom($data['employment_status'] ?? '') ?? EmploymentStatus::Active;
                        $employee->save();
                        $result['created']++;
                    } else {
                        $employee = Employee::query()->findOrFail($item['employee_id']);
                        unset($data['employee_id']);
                        $employee->fill($data)->save();
                        $result['updated']++;
                    }

                    if ($periods !== []) {
                        $result['periods'] += $this->replacePeriods($employee, $periods, $status);
                    }
                }
            })();
        });

        return $result;
    }

    /**
     * Every period from the file, oldest first. Earlier ones ended in a resignation unless the file says otherwise.
     *
     * @param  list<array{from: string, to: ?string}>  $periods
     */
    private function replacePeriods(Employee $employee, array $periods, ?EmploymentStatus $status): int
    {
        EmploymentPeriod::query()->where('employee_id', $employee->id)->delete();

        foreach ($periods as $index => $period) {
            $isLast = $index === array_key_last($periods);
            $record = new EmploymentPeriod([
                'hired_on' => $period['from'],
                'separated_on' => $period['to'],
                'separation_type' => $period['to'] === null ? null : ($isLast ? ($status ?? EmploymentStatus::Resigned) : EmploymentStatus::Resigned),
                'position' => $employee->position,
                'station_id' => $employee->station_id,
                'classification' => $employee->classification,
            ]);
            $record->employee_id = $employee->id;
            $record->saveQuietly();
        }

        app(EmploymentHistoryService::class)->syncEmployeeFromLatest($employee->fresh());

        return count($periods);
    }

    /**
     * Why a person who is not in the system yet cannot be added, or null when they can.
     *
     * @param  Row  $row
     */
    private function newPersonProblem(array $row): ?string
    {
        if (! isset($row['data']['gender'])) {
            return 'Not in the system yet, and the file has no usable gender (required for a new employee)';
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $headers
     * @return array<string, list<int>>
     */
    private function columns(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            $label = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $header)));

            if ($label === 'contact number') {
                $key = isset($map['contact_number']) ? 'emergency_contact_number' : 'contact_number';
            } elseif ($label === 'address') {
                $key = isset($map['address']) ? 'emergency_address' : 'address';
            } else {
                $key = self::COLUMNS[$label] ?? null;
            }

            if ($key !== null) {
                $map[$key][] = $index;
            }
        }

        foreach (['last_name', 'first_name'] as $required) {
            if (! isset($map[$required])) {
                throw new RuntimeException("The sheet has no \"{$required}\" column.");
            }
        }

        return $map;
    }

    /**
     * @param  array<int, mixed>  $cells
     * @param  array<string, list<int>>  $columns
     * @return Row|null
     */
    private function row(int $line, array $cells, array $columns): ?array
    {
        $get = fn (string $key): mixed => isset($columns[$key]) ? ($cells[$columns[$key][0]] ?? null) : null;
        $text = function (string $key) use ($get): ?string {
            $value = trim(preg_replace('/\s+/u', ' ', (string) $get($key)));

            return $value === '' || in_array(mb_strtolower($value), ['none', 'n/a', 'na', '-', '—'], true) ? null : $value;
        };
        $last = $text('last_name');
        $first = $text('first_name');

        if ($last === null || $first === null) {
            return null;
        }

        $warnings = [];
        $data = [];

        foreach (self::TEXT_FIELDS as $field) {
            $data[$field] = $text($field);
        }

        $data['employee_id'] = $this->employeeId($text('employee_id'));
        $data['gender'] = match (mb_strtolower((string) $text('gender'))) {
            'm', 'male' => Gender::Male->value,
            'f', 'female' => Gender::Female->value,
            default => null,
        };
        $data['date_of_birth'] = $this->date($get('date_of_birth'), $warnings, 'Date of birth');
        $data['civil_status'] = $this->civilStatus($text('civil_status'), $warnings);
        $data['blood_type'] = $this->enum(BloodType::class, $text('blood_type'), $warnings, 'Blood type');
        $data['educational_attainment'] = $this->education($text('educational_attainment'), $warnings);
        $data['region'] = $this->region($text('region'), $warnings);
        $data['email'] = $this->email($text('email'), $warnings);
        $data['bank_name'] = $data['bank_account_number'] !== null ? 'Land Bank of the Philippines' : null;

        foreach (['sss', 'pagibig', 'tin', 'philhealth'] as $kind) {
            $value = $text($kind);
            $error = $value === null ? null : GovId::validate($kind, $value);

            if ($error !== null) {
                $warnings[] = $error.'; left unchanged';
            }

            $data[$kind] = $value !== null && $error === null ? GovId::format($kind, $value) : null;
        }

        $fileStatus = $text('file_status');
        [$position, $hint] = $this->position($text('position'));
        $hint ??= mb_strtolower((string) $fileStatus) === 'resigned' ? EmploymentStatus::Resigned : null;
        $data['position'] = $position;
        $data['date_hired'] = $this->date($get('date_hired'), $warnings, 'Date hired');
        $periods = $this->periods($data['date_hired'], [
            $this->date($get('end1'), $warnings, 'End 1'), $this->date($get('rehire1'), $warnings, 'Rehire'),
            $this->date($get('end2'), $warnings, 'End 2'), $this->date($get('rehire2'), $warnings, 'Rehire 2'),
            $this->date($get('end3'), $warnings, 'End 3'), $this->date($get('rehire3'), $warnings, 'Rehire 3'),
        ], $warnings);

        if (mb_strtolower((string) $fileStatus) === 'active' && $periods !== [] && $periods[array_key_last($periods)]['to'] !== null) {
            $warnings[] = 'Marked Active but has an End date; treated as still active';
            $periods[array_key_last($periods)]['to'] = null;
        }

        return [
            'line' => $line,
            'file_status' => $fileStatus,
            'last_name' => $last,
            'first_name' => $first,
            'middle_name' => $data['middle_name'],
            'data' => array_filter($data, fn (mixed $value): bool => $value !== null),
            'periods' => $periods,
            'warnings' => $warnings,
            'station' => (string) $text('station'),
            'status_hint' => $hint,
        ];
    }

    private function nameKey(?string $last, ?string $first): string
    {
        return mb_strtolower(trim((string) $last).'|'.trim((string) $first));
    }

    private function employeeId(?string $id): ?string
    {
        return $id === null || preg_match('/^0+[-\s]*0*$/', $id) ? null : $id;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function date(mixed $value, array &$warnings, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $date = CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value));
            } else {
                $date = CarbonImmutable::parse((string) $value);
            }
        } catch (Throwable) {
            $warnings[] = "{$label} could not be read";

            return null;
        }

        if ($date->year < 1930 || $date->year > 2100) {
            $warnings[] = "{$label} {$date->toDateString()} is not a sensible date";

            return null;
        }

        return $date->toDateString();
    }

    /**
     * @param  list<string>  $warnings
     */
    private function civilStatus(?string $value, array &$warnings): ?string
    {
        if ($value === null) {
            return null;
        }

        $normal = ['widow' => 'Widowed', 'widower' => 'Widowed', 'solo parent' => 'Solo Parent'][mb_strtolower($value)] ?? Str::title($value);
        $found = CivilStatus::tryFrom($normal);

        if ($found === null) {
            $warnings[] = "Civil status \"{$value}\" is not one of the choices; left unchanged";
        }

        return $found?->value;
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     * @param  list<string>  $warnings
     */
    private function enum(string $enum, ?string $value, array &$warnings, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        $found = $enum::tryFrom(mb_strtoupper(str_replace(' ', '', $value)));

        if ($found === null) {
            $warnings[] = "{$label} \"{$value}\" is not one of the choices; left unchanged";
        }

        return $found?->value;
    }

    /**
     * "High School Graduate, College Graduate" is the highest of the levels named.
     *
     * @param  list<string>  $warnings
     */
    private function education(?string $value, array &$warnings): ?string
    {
        if ($value === null) {
            return null;
        }

        $best = null;
        $order = array_flip(array_map(fn (EducationalAttainment $case): string => $case->value, EducationalAttainment::cases()));

        foreach (preg_split('/\s*[,;\/]\s*/', $value) as $part) {
            $text = mb_strtolower($part);
            $level = match (true) {
                str_contains($text, 'doctor') && str_contains($text, 'unit') => EducationalAttainment::DoctoralUnits,
                str_contains($text, 'doctor') => EducationalAttainment::DoctoralDegree,
                str_contains($text, 'master') && str_contains($text, 'unit') => EducationalAttainment::MastersUnits,
                str_contains($text, 'master') => EducationalAttainment::MastersDegree,
                str_contains($text, 'college') && str_contains($text, 'under') => EducationalAttainment::CollegeUndergraduate,
                str_contains($text, 'college') => EducationalAttainment::CollegeGraduate,
                str_contains($text, 'vocational'), str_contains($text, 'technical') => EducationalAttainment::VocationalTechnical,
                str_contains($text, 'senior') => EducationalAttainment::SeniorHighSchoolGraduate,
                str_contains($text, 'high school') && str_contains($text, 'under') => EducationalAttainment::HighSchoolUndergraduate,
                str_contains($text, 'high school') => EducationalAttainment::HighSchoolGraduate,
                str_contains($text, 'elementary') && str_contains($text, 'under') => EducationalAttainment::ElementaryUndergraduate,
                str_contains($text, 'elementary') => EducationalAttainment::ElementaryGraduate,
                default => null,
            };

            if ($level !== null && ($best === null || $order[$level->value] > $order[$best->value])) {
                $best = $level;
            }
        }

        if ($best === null) {
            $warnings[] = "Educational attainment \"{$value}\" is not one of the choices; left unchanged";
        }

        return $best?->value;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function region(?string $value, array &$warnings): ?string
    {
        if ($value === null) {
            return null;
        }

        $found = Region::tryFrom($value) ?? (['caraga' => Region::RegionXiii, 'barmm' => Region::Barmm][mb_strtolower($value)] ?? null);

        if ($found === null) {
            $warnings[] = "Region \"{$value}\" is not one of the choices; left unchanged";
        }

        return $found?->value;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function email(?string $value, array &$warnings): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower($value);

        if (GovId::validateEmail($value) !== null) {
            $warnings[] = "Email \"{$value}\" is not valid; left unchanged";

            return null;
        }

        return $value;
    }

    /**
     * "Resigned-A/Letter Carrier" is the position Letter Carrier with a resignation noted.
     *
     * @return array{0: ?string, 1: ?EmploymentStatus}
     */
    private function position(?string $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        $hint = null;

        if (preg_match('/^(resigned|terminated|retired)\b[\s\-:]*(.*)$/iu', $value, $found)) {
            $hint = ['resigned' => EmploymentStatus::Resigned, 'terminated' => EmploymentStatus::Terminated, 'retired' => EmploymentStatus::Retired][mb_strtolower($found[1])];
            $value = trim($found[2]);
            $value = preg_replace('/^A\//u', 'Acting ', $value);
            $value = $value === '' ? null : $value;
        }

        return [$value, $hint];
    }

    /**
     * Hired, then End 1, Rehire, End 2, Rehire 2, End 3, Rehire 3, as periods in order. Dates out of order are dropped.
     *
     * @param  list<?string>  $dates  End 1, Rehire, End 2, Rehire 2, End 3, Rehire 3
     * @param  list<string>  $warnings
     * @return list<array{from: string, to: ?string}>
     */
    private function periods(?string $hired, array $dates, array &$warnings): array
    {
        if ($hired === null) {
            return [];
        }

        $periods = [['from' => $hired, 'to' => null]];

        foreach (array_chunk($dates, 2) as [$end, $rehire]) {
            $current = array_key_last($periods);

            if ($end !== null) {
                if ($end < $periods[$current]['from']) {
                    $warnings[] = "An end date ({$end}) is before the start of that period; history left as far as it was readable";

                    break;
                }

                $periods[$current]['to'] = $end;
            }

            if ($rehire !== null) {
                if ($periods[$current]['to'] === null || $rehire < $periods[$current]['to']) {
                    $warnings[] = "A rehire date ({$rehire}) does not follow an end date; ignored";

                    continue;
                }

                $periods[] = ['from' => $rehire, 'to' => null];
            }
        }

        return $periods;
    }
}
