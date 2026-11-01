<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Support\ExcelCells;
use App\Support\ZipGuard;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Excel export and import of one payroll period. Columns follow the payslip template's pay items.
 *
 * @phpstan-import-type GridRow from PayrollService
 *
 * @phpstan-type ImportProblem array{row?: int, column?: string, message: string}
 * @phpstan-type ImportPlan array{rows: list<GridRow>, labels: array<int, string>, actions: array<int, string>, errors: list<ImportProblem>, warnings: list<string>}
 */
class PayrollWorkbook
{
    private const ID_COLUMN = 'Employee ID';

    private const REMARKS_COLUMN = 'Remarks';

    private const MONEY_FORMAT = '#,##0.00';

    private const COMPUTED_NOTE = 'Calculated by the system. Ignored when importing.';

    public function __construct(private PayrollService $payroll) {}

    public function export(PayrollPeriod $period): Spreadsheet
    {
        $labels = $this->payroll->itemLabels();
        $slips = $period->payslips()->orderBy('full_name')->get();

        $columns = [
            ['header' => self::ID_COLUMN, 'width' => 14, 'text' => true, 'value' => fn (Payslip $slip): string => $slip->employee_code],
            ['header' => 'Name', 'width' => 28, 'text' => true, 'computed' => true, 'value' => fn (Payslip $slip): string => $slip->full_name],
        ];

        foreach ($labels['earnings'] as $label) {
            $columns[] = ['header' => $label, 'width' => 14, 'value' => fn (Payslip $slip): float => $this->amount($slip->earnings, $label)];
        }

        $columns[] = ['header' => 'Gross Pay', 'width' => 14, 'computed' => true, 'value' => fn (Payslip $slip): float => $slip->gross_pay_centavos / 100];

        foreach ($labels['deductions'] as $label) {
            $columns[] = ['header' => $label, 'width' => 14, 'value' => fn (Payslip $slip): float => $this->amount($slip->deductions, $label)];
        }

        $columns[] = ['header' => 'Total Deductions', 'width' => 16, 'computed' => true, 'value' => fn (Payslip $slip): float => $slip->total_deductions_centavos / 100];
        $columns[] = ['header' => 'Net Pay', 'width' => 14, 'computed' => true, 'value' => fn (Payslip $slip): float => $slip->net_pay_centavos / 100];
        $columns[] = ['header' => self::REMARKS_COLUMN, 'width' => 30, 'text' => true, 'value' => fn (Payslip $slip): string => (string) $slip->remarks];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Payroll');

        foreach ($columns as $index => $column) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $computed = $column['computed'] ?? false;
            $text = $column['text'] ?? false;

            $sheet->getColumnDimension($letter)->setWidth($column['width']);
            $sheet->getCell("{$letter}1")->setValueExplicit($column['header'], DataType::TYPE_STRING);
            $sheet->getStyle("{$letter}1")->getFont()->setBold(true);
            $sheet->getStyle("{$letter}1")->getAlignment()->setWrapText(true);
            $sheet->getStyle("{$letter}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($computed ? 'FFF1F1F1' : 'FFE8EEF8');

            if ($computed) {
                $sheet->getComment("{$letter}1")->getText()->createTextRun(self::COMPUTED_NOTE);
            }

            foreach ($slips as $rowIndex => $slip) {
                $row = $rowIndex + 2;
                $value = $column['value']($slip);

                // Text stays text, so a value starting with "=" can never become a formula.
                $sheet->getCell("{$letter}{$row}")->setValueExplicit($value, $text ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC);
                $sheet->getStyle("{$letter}{$row}")->getNumberFormat()->setFormatCode($text ? NumberFormat::FORMAT_TEXT : self::MONEY_FORMAT);
            }
        }

        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->freezePane('C2');

        return $spreadsheet;
    }

    public function filename(PayrollPeriod $period): string
    {
        return preg_replace('/[^\w.-]/', '_', "payroll-{$period->code}.xlsx");
    }

    /**
     * Read a workbook against a period. Nothing is written.
     *
     * @return ImportPlan
     */
    public function analyze(PayrollPeriod $period, string $path): array
    {
        $plan = ['rows' => [], 'labels' => [], 'actions' => [], 'errors' => [], 'warnings' => []];
        $failed = fn (string $message): array => [...$plan, 'errors' => [['message' => $message]]];

        $zipError = ZipGuard::check((string) file_get_contents($path));

        if ($zipError !== null) {
            return $failed($zipError);
        }

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (Throwable) {
            return $failed('This file could not be read. Upload an .xlsx Excel workbook.');
        }

        $sheet = $this->firstUsedSheet($spreadsheet);

        if ($sheet === null) {
            return $failed('The workbook is empty.');
        }

        $columnOf = $this->headerColumns($sheet);
        $idColumn = $columnOf[ExcelCells::headerKey(self::ID_COLUMN)] ?? null;

        if ($idColumn === null) {
            return $failed('Missing the "'.self::ID_COLUMN.'" column.');
        }

        $labels = $this->payroll->itemLabels();
        $itemColumns = [];
        $missing = [];

        foreach (['e' => $labels['earnings'], 'd' => $labels['deductions']] as $kind => $list) {
            foreach ($list as $label) {
                $column = $columnOf[ExcelCells::headerKey($label)] ?? null;

                if ($column === null) {
                    $missing[] = $label;
                } else {
                    $itemColumns["{$kind}:{$label}"] = $column;
                }
            }
        }

        if ($missing !== []) {
            $plan['warnings'][] = 'Columns not in the file (left unchanged): '.implode(', ', $missing).'.';
        }

        $remarksColumn = $columnOf[ExcelCells::headerKey(self::REMARKS_COLUMN)] ?? null;
        $employees = Employee::query()->get(['id', 'employee_id', 'full_name'])->keyBy(fn (Employee $employee): string => mb_strtolower($employee->employee_id));
        $withSlip = array_flip($period->payslips()->pluck('employee_id')->all());
        $seen = [];

        for ($row = 2, $last = $sheet->getHighestDataRow(); $row <= $last; $row++) {
            $code = ExcelCells::text(ExcelCells::plain($sheet->getCell([$idColumn, $row])));
            $values = [];

            foreach ($itemColumns as $key => $column) {
                $value = ExcelCells::plain($sheet->getCell([$column, $row]));

                if (! ExcelCells::isBlank($value)) {
                    $values[$key] = trim((string) $value);
                }
            }

            if ($code === null && $values === []) {
                continue;
            }

            if ($code === null) {
                $plan['errors'][] = ['row' => $row, 'column' => self::ID_COLUMN, 'message' => 'is required'];

                continue;
            }

            $employee = $employees->get(mb_strtolower($code));

            if ($employee === null) {
                $plan['errors'][] = ['row' => $row, 'column' => self::ID_COLUMN, 'message' => "no employee with Employee ID \"{$code}\""];

                continue;
            }

            if (isset($seen[$employee->id])) {
                $plan['errors'][] = ['row' => $row, 'message' => "Duplicate of row {$seen[$employee->id]} (same employee)."];

                continue;
            }

            $seen[$employee->id] = $row;
            $hasSlip = isset($withSlip[$employee->id]);

            if (! $hasSlip && $period->isReleased()) {
                $plan['errors'][] = ['row' => $row, 'message' => 'This period was already released, so no new payslip can be added.'];

                continue;
            }

            $gridRow = ['employee_id' => $employee->id, 'values' => $values + array_fill_keys(array_keys($itemColumns), '0'), 'line' => $row];

            if ($remarksColumn !== null) {
                $gridRow['remarks'] = (string) ExcelCells::text(ExcelCells::plain($sheet->getCell([$remarksColumn, $row])));
            }

            $index = count($plan['rows']);
            $plan['rows'][] = $gridRow;
            $plan['labels'][$index] = $employee->full_name;
            $plan['actions'][$index] = $hasSlip ? 'update' : 'create';
        }

        if ($period->isReleased()) {
            $plan['warnings'][] = 'This period was already released: changed payslips will be marked "corrected after release".';
        }

        if ($plan['rows'] === [] && $plan['errors'] === []) {
            $plan['errors'][] = ['message' => 'No rows to import.'];
        }

        return $plan;
    }

    /**
     * Save a checked plan with the same rules and transaction as the grid.
     *
     * @param  ImportPlan  $plan
     * @return array{errors: list<ImportProblem>, updated: int, created: int}
     */
    public function commit(PayrollPeriod $period, array $plan): array
    {
        $result = $this->payroll->saveRows($period, $plan['rows']);

        $result['errors'] = array_map(fn (array $error): array => [
            'row' => $plan['rows'][$error['row']]['line'] ?? null,
            'message' => $error['message'],
        ], $result['errors']);

        return $result;
    }

    /**
     * @param  list<array{label: string, amount_centavos: int}>|null  $items
     */
    private function amount(?array $items, string $label): float
    {
        return (array_column($items ?? [], 'amount_centavos', 'label')[$label] ?? 0) / 100;
    }

    private function firstUsedSheet(Spreadsheet $spreadsheet): ?Worksheet
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if ($this->headerColumns($sheet) !== []) {
                return $sheet;
            }
        }

        return null;
    }

    /**
     * Column numbers by normalised header text.
     *
     * @return array<string, int>
     */
    private function headerColumns(Worksheet $sheet): array
    {
        $columns = [];
        $last = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($column = 1; $column <= $last; $column++) {
            $header = ExcelCells::headerKey(ExcelCells::plain($sheet->getCell([$column, 1])));

            if ($header !== '') {
                $columns[$header] = $column;
            }
        }

        return $columns;
    }
}
