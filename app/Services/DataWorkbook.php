<?php

namespace App\Services;

use App\Models\ComplianceRecord;
use App\Models\SiteSetting;
use App\Models\WellnessLeave;
use App\Support\HrDate;
use App\Support\ImportModules;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Excel export: one sheet per module, headers exactly as HR uses them, IDs kept as text and dates as
 * real Excel dates. The import reads the same columns, so an exported file can be edited and sent back.
 *
 * @phpstan-import-type Module from ImportModules
 * @phpstan-import-type ModuleField from ImportModules
 */
class DataWorkbook
{
    private const HEADER_FILL = 'FFE8EEF8';

    private const COMPUTED_FILL = 'FFF1F1F1';

    private const SORTS = [
        'employees' => ['last_name', 'first_name', 'employee_id'],
        'branches' => ['name'],
        'holidays' => ['date'],
        'applications' => ['date_applied', 'application_id'],
        'wellness-leaves' => ['last_name', 'first_name', 'inclusive_date_from'],
    ];

    public function __construct(private LeaveService $leave) {}

    /**
     * A query for a module's records in export order.
     *
     * @param  Module  $module
     * @return Builder<Model>
     */
    public function query(array $module): Builder
    {
        $modelClass = ImportModules::MODELS[$module['slug']];
        $query = $modelClass::query()->with(array_values(array_filter(['station', 'employee'], fn (string $relation): bool => method_exists($modelClass, $relation))));

        if (is_subclass_of($modelClass, ComplianceRecord::class)) {
            $query->orderByDesc('year')->orderBy('last_name')->orderBy('first_name');
        }

        foreach (self::SORTS[$module['slug']] ?? [] as $column) {
            $query->orderBy($column);
        }

        return $query;
    }

    /**
     * @param  list<array{module: Module, query: Builder<Model>}>  $requests
     */
    public function build(array $requests): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $sheets = [];

        foreach ($requests as $request) {
            $records = $request['query']->get();
            $sheets[] = ['module' => $request['module'], 'records' => $records, 'remaining' => $this->remainingDays($request['module'], $records)];
        }

        if (count($sheets) > 1) {
            $this->readme($spreadsheet, array_map(fn (array $sheet): array => [$sheet['module']['sheet'], $sheet['records']->count()], $sheets), SiteSetting::current()->company_name.' — HR data export');
        }

        foreach ($sheets as $sheet) {
            $this->addSheet($spreadsheet, $sheet['module'], $sheet['records'], $sheet['remaining']);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * An empty workbook with headers, dropdowns and notes.
     *
     * @param  list<Module>  $modules
     */
    public function template(array $modules): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $this->readme($spreadsheet, array_map(fn (array $module): array => [$module['sheet'], 0], $modules), 'Import template');

        foreach ($modules as $module) {
            $this->addSheet($spreadsheet, $module, new Collection, []);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  Module  $module
     * @param  Collection<int, Model>  $records
     * @param  array<int, int>  $remaining
     */
    private function addSheet(Spreadsheet $spreadsheet, array $module, Collection $records, array $remaining): void
    {
        $sheet = $spreadsheet->createSheet()->setTitle($module['sheet']);
        $fields = array_values($module['fields']);

        foreach ($fields as $index => $field) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $computed = $field['computed'] ?? false;

            $sheet->getColumnDimension($letter)->setWidth($field['width'] ?? max(12, min(28, mb_strlen($field['label']) + 4)));
            $sheet->getCell("{$letter}1")->setValueExplicit($field['label'], DataType::TYPE_STRING);
            $sheet->getStyle("{$letter}1")->getFont()->setBold(true);
            $sheet->getStyle("{$letter}1")->getAlignment()->setWrapText(true);
            $sheet->getStyle("{$letter}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($computed ? self::COMPUTED_FILL : self::HEADER_FILL);

            if ($computed) {
                $sheet->getComment("{$letter}1")->getText()->createTextRun('Calculated by the system. Ignored when importing.');
            } elseif ($field['required'] ?? false) {
                $sheet->getComment("{$letter}1")->getText()->createTextRun('Required');
            }

            $row = 2;

            foreach ($records as $record) {
                $this->writeCell($sheet->getCell("{$letter}{$row}"), $field, $record, $remaining);
                $sheet->getStyle("{$letter}{$row}")->getNumberFormat()->setFormatCode(match ($field['kind']) {
                    'date' => 'yyyy-mm-dd',
                    'number', 'year' => NumberFormat::FORMAT_GENERAL,
                    default => NumberFormat::FORMAT_TEXT,
                });
                $row++;
            }

            $this->addDropdown($sheet, $field, $letter, max($records->count() + 200, 1000));
        }

        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.Coordinate::stringFromColumnIndex(count($fields)).'1');
    }

    /**
     * @param  ModuleField  $field
     * @param  array<int, int>  $remaining
     */
    private function writeCell(mixed $cell, array $field, Model $record, array $remaining): void
    {
        $value = $this->valueOf($field, $record, $remaining);

        if ($value === null || $value === '') {
            return;
        }

        match ($field['kind']) {
            'date' => $cell->setValueExplicit(ExcelDate::stringToExcel(substr((string) $value, 0, 10)), DataType::TYPE_NUMERIC),
            'number', 'year' => $cell->setValueExplicit((float) $value, DataType::TYPE_NUMERIC),
            // Text stays text, so a value starting with "=" can never become a formula.
            default => $cell->setValueExplicit((string) $value, DataType::TYPE_STRING),
        };
    }

    /**
     * @param  ModuleField  $field
     * @param  array<int, int>  $remaining
     */
    private function valueOf(array $field, Model $record, array $remaining): mixed
    {
        if ($field['name'] === 'remaining_days') {
            return $remaining[$record->getKey()] ?? null;
        }

        $value = match ($field['kind']) {
            'branch' => $record->getRelationValue($field['name'])?->name,
            'employee' => $record->getRelationValue('employee')?->employee_id,
            default => $record->getAttribute($field['name']),
        };

        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_array($value) => $value['label'] ?? null,
            default => $value,
        };
    }

    /**
     * Excel limits inline lists to 255 characters.
     *
     * @param  ModuleField  $field
     */
    private function addDropdown(mixed $sheet, array $field, string $letter, int $lastRow): void
    {
        if ($field['kind'] !== 'select' || ($field['computed'] ?? false)) {
            return;
        }

        $list = '"'.implode(',', $field['options'] ?? []).'"';

        if (mb_strlen($list) > 255) {
            return;
        }

        $validation = new DataValidation;
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setAllowBlank(! ($field['required'] ?? false));
        $validation->setShowDropDown(true);
        $validation->setShowErrorMessage(true);
        $validation->setErrorTitle($field['label']);
        $validation->setError('Choose one of: '.implode(', ', $field['options'] ?? []));
        $validation->setFormula1($list);

        $sheet->setDataValidation("{$letter}2:{$letter}{$lastRow}", $validation);
    }

    /**
     * @param  Module  $module
     * @param  Collection<int, Model>  $records
     * @return array<int, int>
     */
    private function remainingDays(array $module, Collection $records): array
    {
        return $module['slug'] === 'wellness-leaves' && $records->first() instanceof WellnessLeave ? $this->leave->remainingAfter($records) : [];
    }

    /**
     * @param  list<array{0: string, 1: int}>  $counts
     */
    private function readme(Spreadsheet $spreadsheet, array $counts, string $title): void
    {
        $sheet = $spreadsheet->createSheet()->setTitle('README');
        $sheet->getColumnDimension('A')->setWidth(110);

        $lines = [
            $title,
            'Exported '.now('Asia/Manila')->format('M j, Y g:i A').' (Asia/Manila)',
            '',
            ...array_map(fn (array $count): string => "{$count[0]}: {$count[1]} row(s)", $counts),
            '',
            'How to edit and import back:',
            '• Keep the header row exactly as it is. Grey columns are calculated by the system and are ignored on import.',
            '• Records are matched by their key: Employee ID (employees), Application ID (onboarding), Branch Name (branches),',
            '  Date (holidays), Employee ID + Inclusive Date From (wellness leave), Employee ID + Year [+ Rating Period] (requirements).',
            '• A blank cell clears that value. Rows you delete from the file are NOT deleted from the system.',
            '• Type dates as real Excel dates or as YYYY-MM-DD. ID numbers (SSS, TIN, …) are text — keep leading zeros.',
            '• Every row is checked before anything is saved; if any row has an error, nothing is imported.',
        ];

        foreach ($lines as $index => $line) {
            $sheet->getCell('A'.($index + 1))->setValueExplicit($line, DataType::TYPE_STRING);
        }

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    }

    public function filename(string $base): string
    {
        return "{$base}-".HrDate::today().'.xlsx';
    }
}
