<?php

namespace App\Services;

use App\Enums\ImportJobStatus;
use App\Exceptions\UserFacingException;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\ImportJob;
use App\Models\User;
use App\Models\WellnessLeave;
use App\Support\AuditTrail;
use App\Support\ExcelCells;
use App\Support\GovId;
use App\Support\ImportModules;
use App\Support\RecordForm;
use App\Support\RecordRules;
use App\Support\ZipGuard;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Excel import with three guarantees: every cell is checked, every write is rehearsed against the
 * real rules in a transaction that is rolled back, and the commit is one transaction, so either
 * every row is saved or none is. Imports never send email.
 *
 * @phpstan-import-type Module from ImportModules
 * @phpstan-import-type ModuleField from ImportModules
 *
 * @phpstan-type Issue array{sheet: string, row?: int, column?: string, message: string}
 * @phpstan-type RowPlan array{module: Module, sheet: string, row: int, label: string, action: string, model: Model|null, values: array<string, mixed>, refs: array<string, array{kind: string, id?: int, pending?: string}>, changes: array<string, array{from: mixed, to: mixed}>}
 * @phpstan-type SheetSummary array{sheet: string, title: string, rows: int, create: int, update: int, unchanged: int, errors: int}
 * @phpstan-type ImportPlan array{sheets: list<SheetSummary>, plans: list<RowPlan>, errors: list<Issue>, warnings: list<string>}
 */
class DataImporter
{
    public const MAX_ROWS_PER_SHEET = 10_000;

    public const MAX_REHEARSAL_ERRORS = 50;

    public function __construct(private LeaveService $leave) {}

    /**
     * Static checks, then a rolled-back rehearsal when those pass. Nothing is saved.
     *
     * @return ImportPlan
     */
    public function validate(string $path, ?string $moduleHint, User $user): array
    {
        $plan = $this->analyze($path, $moduleHint);

        if ($plan['errors'] === [] && $plan['plans'] !== []) {
            foreach ($this->rehearse($plan, $user) as $issue) {
                $plan['errors'][] = $issue;

                foreach ($plan['sheets'] as $index => $sheet) {
                    if ($sheet['sheet'] === $issue['sheet']) {
                        $plan['sheets'][$index]['errors']++;
                    }
                }
            }
        }

        return $plan;
    }

    /**
     * Work out exactly what a workbook would do.
     *
     * @return ImportPlan
     */
    public function analyze(string $path, ?string $moduleHint): array
    {
        $plan = ['sheets' => [], 'plans' => [], 'errors' => [], 'warnings' => []];
        $fail = fn (string $message): array => [...$plan, 'errors' => [['sheet' => '', 'message' => $message]]];

        $zipError = ZipGuard::check((string) file_get_contents($path));

        if ($zipError !== null) {
            return $fail($zipError);
        }

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (Throwable) {
            return $fail('This file could not be read. Upload an .xlsx Excel workbook.');
        }

        $date1904 = $spreadsheet->getExcelCalendar() === ExcelDate::CALENDAR_MAC_1904;
        $worksheets = array_values(array_filter($spreadsheet->getAllSheets(), fn (Worksheet $sheet): bool => ExcelCells::headerKey($sheet->getTitle()) !== 'readme' && $this->columnCount($sheet) > 0));
        $hint = $moduleHint && count($worksheets) === 1 ? ImportModules::find($moduleHint) : null;
        $sheets = [];

        foreach ($worksheets as $worksheet) {
            $module = $this->moduleForSheet($worksheet->getTitle()) ?? $hint;

            if ($module === null) {
                $plan['warnings'][] = "Sheet \"{$worksheet->getTitle()}\" was ignored (its name doesn't match a module).";

                continue;
            }

            if (isset($sheets[$module['slug']])) {
                $plan['errors'][] = ['sheet' => $worksheet->getTitle(), 'message' => "Two sheets are for {$module['title']}. Keep only one."];

                continue;
            }

            $columns = $this->readColumns($worksheet, $module, $plan);

            if ($columns !== null) {
                $sheets[$module['slug']] = ['module' => $module, 'worksheet' => $worksheet, 'columns' => $columns];
            }
        }

        $order = array_column(ImportModules::all(), 'slug');
        uksort($sheets, fn (string $first, string $second): int => array_search($first, $order, true) <=> array_search($second, $order, true));

        $lookups = $this->lookups($sheets);

        foreach ($sheets as $sheet) {
            $this->planSheet($sheet['module'], $sheet['worksheet'], $sheet['columns'], $lookups, $date1904, $plan);
        }

        usort($plan['plans'], function (array $first, array $second) use ($order): int {
            $module = array_search($first['module']['slug'], $order, true) <=> array_search($second['module']['slug'], $order, true);

            if ($module !== 0 || $first['module']['slug'] !== 'wellness-leaves') {
                return $module;
            }

            // Allowance checks depend on order, so filings apply chronologically.
            return [$first['values']['inclusive_date_from'] ?? '', $first['row']] <=> [$second['values']['inclusive_date_from'] ?? '', $second['row']];
        });

        return $plan;
    }

    /**
     * Run every change through the real rules, then undo it all. A failed row rolls back to its own
     * savepoint, so later rows are checked against the state the real import would see.
     *
     * @param  ImportPlan  $plan
     * @return list<Issue>
     */
    public function rehearse(array $plan, User $user): array
    {
        $issues = [];
        $created = [];

        DB::beginTransaction();

        try {
            foreach ($plan['plans'] as $row) {
                if ($row['action'] === 'unchanged') {
                    continue;
                }

                try {
                    DB::transaction(function () use ($row, &$created, $user): void {
                        $this->apply($row, $created, $user);
                    });
                } catch (Throwable $exception) {
                    $issues[] = $this->issueFrom($row, $exception);

                    if (count($issues) >= self::MAX_REHEARSAL_ERRORS) {
                        break;
                    }
                }
            }
        } finally {
            DB::rollBack();
        }

        return $issues;
    }

    /**
     * Write the plan in one transaction and record it in the import history.
     *
     * @param  ImportPlan  $plan
     * @return array{created: int, updated: int, unchanged: int, issue: Issue|null}
     */
    public function commit(array $plan, User $user, string $fileName, string $archiveName): array
    {
        $count = fn (string $action): int => count(array_filter($plan['plans'], fn (array $row): bool => $row['action'] === $action));
        $issue = null;
        $current = null;
        $created = [];

        try {
            DB::transaction(function () use ($plan, &$created, &$current, $user): void {
                foreach ($plan['plans'] as $row) {
                    $current = $row;

                    if ($row['action'] !== 'unchanged') {
                        $this->apply($row, $created, $user);
                    }
                }
            });
        } catch (Throwable $exception) {
            $issue = $this->issueFrom($current, $exception);
        }

        $job = new ImportJob([
            'file_name' => $fileName,
            'module' => implode(', ', array_column($plan['sheets'], 'title')) ?: '—',
            'user_id' => $user->id,
            'user_name' => $user->name ?: $user->email,
            'status' => $issue === null ? ImportJobStatus::Committed : ImportJobStatus::Failed,
            'created' => $issue === null ? $count('create') : 0,
            'updated' => $issue === null ? $count('update') : 0,
            'unchanged' => $issue === null ? $count('unchanged') : 0,
            'error' => $issue === null ? null : "{$issue['sheet']} row ".($issue['row'] ?? '?').": {$issue['message']}",
            'archive_file' => $issue === null ? $archiveName : null,
        ]);
        $job->save();

        return ['created' => $count('create'), 'updated' => $count('update'), 'unchanged' => $count('unchanged'), 'issue' => $issue];
    }

    /**
     * @param  Module  $module
     * @param  ImportPlan  $plan
     * @return array<int, ModuleField>|null Field per column number, or null when the sheet can't be used
     */
    private function readColumns(Worksheet $worksheet, array $module, array &$plan): ?array
    {
        $title = $worksheet->getTitle();
        $columns = [];
        $seen = [];

        for ($column = 1, $last = $this->columnCount($worksheet); $column <= $last; $column++) {
            $text = trim((string) ExcelCells::plain($worksheet->getCell([$column, 1])));

            if ($text === '') {
                continue;
            }

            $key = ExcelCells::headerKey($text);
            $field = collect($module['fields'])->first(fn (array $candidate): bool => ExcelCells::headerKey($candidate['label']) === $key);

            if ($field === null) {
                $plan['warnings'][] = "{$title}: column \"{$text}\" is not recognised and was ignored.";

                continue;
            }

            if (isset($seen[$field['name']])) {
                $plan['errors'][] = ['sheet' => $title, 'column' => $field['label'], 'message' => 'This column appears twice.'];

                continue;
            }

            $seen[$field['name']] = true;

            if (! ($field['computed'] ?? false)) {
                $columns[$column] = $field;
            }
        }

        $missing = array_filter($module['fields'], fn (array $field): bool => ! ($field['computed'] ?? false)
            && (($field['required'] ?? false) || in_array(RecordForm::column($field), $module['key'], true))
            && ! isset($seen[$field['name']]));

        if ($missing !== []) {
            $plan['errors'][] = ['sheet' => $title, 'message' => 'Missing required column(s): '.implode(', ', array_column($missing, 'label')).'.'];

            return null;
        }

        if ($worksheet->getHighestDataRow() - 1 > self::MAX_ROWS_PER_SHEET) {
            $plan['errors'][] = ['sheet' => $title, 'message' => 'Too many rows (max '.number_format(self::MAX_ROWS_PER_SHEET).' per sheet). Split the file.'];

            return null;
        }

        return $columns;
    }

    /**
     * @param  Module  $module
     * @param  array<int, ModuleField>  $columns
     * @param  array<string, mixed>  $lookups
     * @param  ImportPlan  $plan
     */
    private function planSheet(array $module, Worksheet $worksheet, array $columns, array $lookups, bool $date1904, array &$plan): void
    {
        $title = $worksheet->getTitle();
        $modelClass = ImportModules::MODELS[$module['slug']];
        $existing = $modelClass::query()->get()->keyBy(fn (Model $model): string => $this->existingKey($module, $model));
        $limits = RecordForm::columnLimits((new $modelClass)->getTable());
        $summary = ['sheet' => $title, 'title' => $module['title'], 'rows' => 0, 'create' => 0, 'update' => 0, 'unchanged' => 0, 'errors' => 0];
        $fileKeys = [];

        for ($rowNumber = 2, $last = $worksheet->getHighestDataRow(); $rowNumber <= $last; $rowNumber++) {
            $cells = [];

            foreach ($columns as $column => $field) {
                $cells[$column] = ExcelCells::plain($worksheet->getCell([$column, $rowNumber]));
            }

            if (array_filter($cells, fn (mixed $value): bool => ! ExcelCells::isBlank($value)) === []) {
                continue;
            }

            $summary['rows']++;
            $values = [];
            $refs = [];
            $hasError = false;

            foreach ($columns as $column => $field) {
                $raw = $cells[$column];

                // A blank "copied from employee" column means "use the employee's details".
                if (($field['fromEmployee'] ?? false) && ExcelCells::isBlank($raw)) {
                    continue;
                }

                $parsed = $this->parseCell($field, $raw, $lookups, $date1904, $limits);
                $name = RecordForm::column($field);

                if (isset($parsed['error'])) {
                    $plan['errors'][] = ['sheet' => $title, 'row' => $rowNumber, 'column' => $field['label'], 'message' => $parsed['error']];
                    $hasError = true;
                } elseif (isset($parsed['ref'])) {
                    $refs[$name] = $parsed['ref'];
                } else {
                    $values[$name] = $parsed['value'];
                }
            }

            if ($hasError) {
                $summary['errors']++;

                continue;
            }

            $key = $this->rowKey($module, $values, $refs);

            if (isset($fileKeys[$key])) {
                $names = array_map(fn (string $column): string => collect($module['fields'])->first(fn (array $field): bool => RecordForm::column($field) === $column)['label'], $module['key']);
                $plan['errors'][] = ['sheet' => $title, 'row' => $rowNumber, 'message' => "Duplicate of row {$fileKeys[$key]} (same ".implode(' + ', $names).').'];
                $summary['errors']++;

                continue;
            }

            $fileKeys[$key] = $rowNumber;
            $model = $existing->get($key);
            $changes = $model ? $this->changes($columns, $model, $values, $refs) : [];
            $action = $model === null ? 'create' : ($changes !== [] ? 'update' : 'unchanged');
            $summary[$action]++;

            $plan['plans'][] = [
                'module' => $module,
                'sheet' => $title,
                'row' => $rowNumber,
                'label' => $this->label($module, $model, $values, $refs, $lookups, $rowNumber),
                'action' => $action,
                'model' => $model,
                'values' => $values,
                'refs' => $refs,
                'changes' => $changes,
            ];
        }

        $plan['sheets'][] = $summary;
    }

    /**
     * @param  ModuleField  $field
     * @param  array<string, mixed>  $lookups
     * @param  array<string, int>  $limits
     * @return array{value?: mixed, ref?: array{kind: string, id?: int, pending?: string}, error?: string}
     */
    private function parseCell(array $field, mixed $raw, array $lookups, bool $date1904, array $limits): array
    {
        if (ExcelCells::isBlank($raw)) {
            return ($field['required'] ?? false) ? ['error' => 'is required'] : ['value' => null];
        }

        $kind = $field['kind'];
        $text = $kind === 'textarea' ? trim((string) $raw) : (string) ExcelCells::text($raw);
        $key = mb_strtolower($text);

        switch ($kind) {
            case 'date':
                [$ymd, $error] = ExcelCells::date($raw, $date1904);

                return $error === null ? ['value' => $ymd] : ['error' => $error];

            case 'number':
            case 'year':
                $text = str_replace(',', '', $text);
                break;

            case 'select':
                foreach ($field['options'] ?? [] as $option) {
                    if (mb_strtolower($option) === $key) {
                        return ['value' => $option];
                    }
                }

                return ['error' => "\"{$text}\" is not one of: ".implode(', ', $field['options'] ?? [])];

            case 'branch':
                $id = $lookups['branchByName'][$key] ?? $lookups['branchByCode'][$key] ?? null;

                if ($id !== null) {
                    return ['ref' => ['kind' => 'branch', 'id' => $id]];
                }

                return isset($lookups['pendingBranches'][$key])
                    ? ['ref' => ['kind' => 'branch', 'pending' => $key]]
                    : ['error' => "branch \"{$text}\" does not exist — add it on the Branches sheet or the Branches page first"];

            case 'employee':
                $id = $lookups['employeeById'][$key] ?? null;

                if ($id !== null) {
                    return ['ref' => ['kind' => 'employee', 'id' => $id]];
                }

                return isset($lookups['pendingEmployees'][$key])
                    ? ['ref' => ['kind' => 'employee', 'pending' => $key]]
                    : ['error' => "no employee with Employee ID \"{$text}\""];

            case 'govId':
                $error = GovId::validate($field['idKind'], $text);

                if ($error !== null) {
                    return ['error' => $error.(is_numeric($raw) ? ' (Excel may have dropped a leading zero — format the column as Text)' : '')];
                }

                break;
        }

        [$value, $error] = RecordForm::convert($field, $text);
        $column = RecordForm::column($field);

        if ($error === null && is_string($value) && isset($limits[$column]) && mb_strlen($value) > $limits[$column]) {
            $error = "must be at most {$limits[$column]} characters";
        }

        return $error === null ? ['value' => $value] : ['error' => $error];
    }

    /**
     * Save one row and record it in the audit log. Runs inside the caller's transaction.
     *
     * @param  RowPlan  $row
     * @param  array<string, int>  $created  New branches and employees, so later rows can link to them
     */
    private function apply(array $row, array &$created, User $user): void
    {
        $data = $row['values'];

        foreach ($row['refs'] as $column => $ref) {
            $data[$column] = $ref['id'] ?? $created["{$ref['kind']}:{$ref['pending']}"]
                ?? throw new UserFacingException("Could not resolve {$ref['kind']} \"{$ref['pending']}\" (its own row failed).");
        }

        $slug = $row['module']['slug'];
        $modelClass = ImportModules::MODELS[$slug];
        // A fresh copy each pass: the rehearsal's changes must not linger in memory after its rollback.
        $model = $row['model'] ? $modelClass::query()->findOrFail($row['model']->getKey()) : new $modelClass;

        AuditTrail::withoutRecording(function () use ($slug, $model, $data, $user): void {
            $model->fill($data);

            $errors = RecordRules::errorsFor($slug, $model);

            if ($errors !== []) {
                throw new UserFacingException(reset($errors));
            }

            if ($model instanceof WellnessLeave) {
                $this->leave->save($model, $data, $user, notify: false);
            } else {
                $model->save();
            }
        });

        if ($model instanceof Branch) {
            $created['branch:'.mb_strtolower($model->name)] = $model->id;
        }

        if ($model instanceof Employee) {
            $created['employee:'.mb_strtolower($model->employee_id)] = $model->id;
        }

        $changes = $row['action'] === 'create' ? ['created' => ['from' => null, 'to' => "row {$row['row']} of {$row['sheet']}"]] : $row['changes'];

        AuditTrail::record('import', $slug, $row['label'], $changes, $model->getKey());
    }

    /**
     * @param  array<int, ModuleField>  $columns
     * @param  array<string, mixed>  $values
     * @param  array<string, array{kind: string, id?: int, pending?: string}>  $refs
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(array $columns, Model $model, array $values, array $refs): array
    {
        $changes = [];

        foreach ($columns as $field) {
            $column = RecordForm::column($field);

            if (! array_key_exists($column, $values) && ! isset($refs[$column])) {
                continue;
            }

            $current = $this->stored($model->getAttribute($column));
            $incoming = isset($refs[$column]) ? ($refs[$column]['id'] ?? "(new) {$refs[$column]['pending']}") : $values[$column];

            if (in_array($field['kind'], ['number', 'year'], true)) {
                $current = $current === null ? null : (float) $current;
                $incoming = $incoming === null ? null : (float) $incoming;
            }

            if ($current !== $incoming && (string) $current !== (string) $incoming) {
                $changes[$column] = ['from' => $current, 'to' => $incoming];
            }
        }

        return $changes;
    }

    private function stored(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            $value === '' => null,
            default => $value,
        };
    }

    /**
     * @param  Module  $module
     * @param  array<string, mixed>  $values
     * @param  array<string, array{kind: string, id?: int, pending?: string}>  $refs
     * @param  array<string, mixed>  $lookups
     */
    private function label(array $module, ?Model $model, array $values, array $refs, array $lookups, int $rowNumber): string
    {
        $employee = $refs['employee_id'] ?? null;
        $employeeCode = $employee === null ? null : ($employee['pending'] ?? ($lookups['employeeCodes'][$employee['id']] ?? null));
        $column = collect(['last_name', 'name', 'applicant_name', 'employee_id'])->first(fn (string $name): bool => array_key_exists($name, $values) || ($model !== null && $model->getAttribute($name) !== null && ! isset($refs[$name])));
        $name = $column ? (string) ($values[$column] ?? $model?->getAttribute($column)) : '';

        return implode(' — ', array_filter([$employeeCode, $name])) ?: "Row {$rowNumber}";
    }

    /**
     * @param  Module  $module
     * @param  array<string, mixed>  $values
     * @param  array<string, array{kind: string, id?: int, pending?: string}>  $refs
     */
    private function rowKey(array $module, array $values, array $refs): string
    {
        return implode('|', array_map(function (string $column) use ($values, $refs): string {
            $ref = $refs[$column] ?? null;

            if ($ref !== null) {
                return isset($ref['id']) ? "{$ref['kind']}:{$ref['id']}" : "pending:{$ref['pending']}";
            }

            return mb_strtolower((string) ($values[$column] ?? ''));
        }, $module['key']));
    }

    /**
     * @param  Module  $module
     */
    private function existingKey(array $module, Model $model): string
    {
        return implode('|', array_map(function (string $column) use ($module, $model): string {
            $field = collect($module['fields'])->first(fn (array $candidate): bool => RecordForm::column($candidate) === $column);
            $value = $this->stored($model->getAttribute($column));

            return $field['kind'] === 'employee' ? "employee:{$value}" : mb_strtolower((string) $value);
        }, $module['key']));
    }

    /**
     * What is saved already, plus the branches and employees this same file adds.
     *
     * @param  array<string, array{module: Module, worksheet: Worksheet, columns: array<int, ModuleField>}>  $sheets
     * @return array<string, mixed>
     */
    private function lookups(array $sheets): array
    {
        $branches = Branch::query()->get(['id', 'name', 'code']);
        $employees = Employee::query()->get(['id', 'employee_id']);

        $lookups = [
            'branchByName' => $branches->mapWithKeys(fn (Branch $branch): array => [mb_strtolower($branch->name) => $branch->id])->all(),
            'branchByCode' => $branches->filter(fn (Branch $branch): bool => filled($branch->code))->mapWithKeys(fn (Branch $branch): array => [mb_strtolower($branch->code) => $branch->id])->all(),
            'employeeById' => $employees->mapWithKeys(fn (Employee $employee): array => [mb_strtolower($employee->employee_id) => $employee->id])->all(),
            'employeeCodes' => $employees->pluck('employee_id', 'id')->all(),
            'pendingBranches' => [],
            'pendingEmployees' => [],
        ];

        foreach (['branches' => ['name', 'branchByName', 'pendingBranches'], 'employees' => ['employee_id', 'employeeById', 'pendingEmployees']] as $slug => [$fieldName, $known, $pending]) {
            if (! isset($sheets[$slug])) {
                continue;
            }

            $column = array_search($fieldName, array_map(fn (array $field): string => $field['name'], $sheets[$slug]['columns']), true);

            if ($column === false) {
                continue;
            }

            $worksheet = $sheets[$slug]['worksheet'];

            for ($row = 2, $last = $worksheet->getHighestDataRow(); $row <= $last; $row++) {
                $value = mb_strtolower((string) ExcelCells::text(ExcelCells::plain($worksheet->getCell([$column, $row]))));

                if ($value !== '' && ! isset($lookups[$known][$value])) {
                    $lookups[$pending][$value] = true;
                }
            }
        }

        return $lookups;
    }

    /**
     * @return Module|null
     */
    private function moduleForSheet(string $name): ?array
    {
        $key = ExcelCells::headerKey($name);

        foreach (ImportModules::all() as $module) {
            if (ExcelCells::headerKey($module['sheet']) === $key || ExcelCells::headerKey($module['title']) === $key || $module['slug'] === $key) {
                return $module;
            }
        }

        return null;
    }

    private function columnCount(Worksheet $worksheet): int
    {
        $last = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());

        for ($column = 1; $column <= $last; $column++) {
            if (! ExcelCells::isBlank(ExcelCells::plain($worksheet->getCell([$column, 1])))) {
                return $last;
            }
        }

        return 0;
    }

    /**
     * @param  RowPlan|null  $row
     * @return Issue
     */
    private function issueFrom(?array $row, Throwable $exception): array
    {
        $location = $row === null ? ['sheet' => ''] : ['sheet' => $row['sheet'], 'row' => $row['row']];

        if ($exception instanceof UserFacingException) {
            return [...$location, 'message' => $exception->getMessage()];
        }

        if ($exception instanceof UniqueConstraintViolationException) {
            return [...$location, 'message' => 'A record with the same unique value already exists.'];
        }

        report($exception);

        return [...$location, 'message' => 'This row could not be saved.'];
    }
}
