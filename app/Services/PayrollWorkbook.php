<?php

namespace App\Services;

use App\Models\PayrollPeriod;
use App\Models\Payslip;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The payroll as an Excel workbook in the office's PAYROLL FORMAT layout: the same title rows, the
 * same 28 columns and the same live formulas, so HR can keep working with it as before.
 */
class PayrollWorkbook
{
    private const MONEY_FORMAT = '#,##0.00';

    private const FIRST_ROW = 6;

    /**
     * Column letter => [header, width, money?]. Formulas and values are filled in per row.
     *
     * @var array<string, array{0: string, 1: float, 2: bool}>
     */
    private const COLUMNS = [
        'A' => ['SPECIFIC STATION', 14, false],
        'B' => ['Period', 6, false],
        'C' => ['Batch', 6, false],
        'D' => ['No.', 5, false],
        'E' => ['Name', 30, false],
        'F' => ['Official Station', 16, false],
        'G' => ['Daily Rate', 10, true],
        'H' => ['15% Premium ', 10, true],
        'I' => ['Total Daily Rate', 10, true],
        'J' => ['Date Period  Covered', 13, false],
        'K' => ['No. of Days', 7, false],
        'L' => ['Gross', 12, true],
        'M' => ['No. of Late (mins)', 8, false],
        'N' => ['Rate/min', 8, true],
        'O' => ['Total Late', 10, true],
        'P' => ['OT Hours', 7, false],
        'Q' => ['Hourly rate', 9, true],
        'R' => ['OT Hours RD/SH', 8, false],
        'S' => ['Hourly rate RD/SH', 9, true],
        'T' => ['Overtime Pay', 10, true],
        'U' => ['Gross after Deduction of Late ', 13, true],
        'V' => ['SSS', 10, true],
        'W' => ['HDMF', 10, true],
        'X' => ['CMPEAI', 10, true],
        'Y' => ['Net Pay', 12, true],
        'Z' => ['Absences', 8, false],
        'AA' => ['tardiness', 8, false],
        'AB' => ['Excess', 10, true],
    ];

    /** Columns added up on the total row. */
    private const TOTALS = ['L', 'O', 'T', 'U', 'V', 'W', 'X', 'Y', 'AB'];

    public function export(PayrollPeriod $period): Spreadsheet
    {
        $slips = $period->payslips()->orderBy('station')->orderBy('full_name')->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet()->setTitle(mb_substr(strtoupper($period->period_start->format('F Y')), 0, 31));

        $this->titles($sheet, $period);
        $this->headers($sheet);

        $row = self::FIRST_ROW;

        foreach ($slips as $index => $slip) {
            $this->workerRow($sheet, $row, $index + 1, $slip, $period);
            $row++;
        }

        $this->totalRow($sheet, $row, $slips->isEmpty() ? null : $row - 1);

        $sheet->freezePane('F'.self::FIRST_ROW);
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_LEGAL)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(4, 5);

        return $spreadsheet;
    }

    public function filename(PayrollPeriod $period): string
    {
        return preg_replace('/[^\w.-]/', '_', "payroll-{$period->code}.xlsx");
    }

    /**
     * Rows 1-3: the payroll title, the period and the batch, centred across E:Y.
     */
    private function titles(Worksheet $sheet, PayrollPeriod $period): void
    {
        $lines = [1 => 'PAYROLL OF CONTRACT OF SERVICE (COS)', 2 => $period->titleRange(), 3 => $period->batch ? 'BATCH '.$period->batch : ''];

        foreach ($lines as $row => $text) {
            $sheet->mergeCells("E{$row}:Y{$row}");
            $sheet->getCell("E{$row}")->setValueExplicit($text, DataType::TYPE_STRING);
            $sheet->getStyle("E{$row}")->getFont()->setBold(true)->setSize($row === 1 ? 14 : 12);
            $sheet->getStyle("E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
    }

    /**
     * Row 4 groups the two overtime pairs; row 5 holds the column headers.
     */
    private function headers(Worksheet $sheet): void
    {
        foreach (['P4:Q4' => 'Regular OT', 'R4:S4' => 'OT during RD/SH'] as $range => $text) {
            $sheet->mergeCells($range);
            $sheet->getCell(explode(':', $range)[0])->setValueExplicit($text, DataType::TYPE_STRING);
        }

        foreach (self::COLUMNS as $letter => [$header, $width]) {
            $sheet->getColumnDimension($letter)->setWidth($width);
            $sheet->getCell("{$letter}5")->setValueExplicit($header, DataType::TYPE_STRING);
        }

        $style = $sheet->getStyle('A4:AB5');
        $style->getFont()->setBold(true);
        $style->getAlignment()->setWrapText(true)->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8EEF8');
        $sheet->getStyle('A5:AB5')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getRowDimension(5)->setRowHeight(42);
    }

    /**
     * One worker, with the workbook's own formulas for every computed column. Text is written as text,
     * so a name starting with "=" can never become a formula.
     */
    private function workerRow(Worksheet $sheet, int $row, int $number, Payslip $slip, PayrollPeriod $period): void
    {
        $premium = rtrim(rtrim(number_format((float) $slip->premium_percent / 100, 4, '.', ''), '0'), '.');

        $text = [
            'A' => (string) $slip->specific_station,
            'C' => (string) $slip->batch_label,
            'E' => mb_strtoupper($slip->full_name),
            'F' => (string) $slip->station,
            'J' => (string) $slip->period_covered,
        ];
        $numbers = [
            'B' => (int) $period->period_start->format('j') <= 15 ? 1 : 2,
            'D' => $number,
            'G' => $slip->daily_rate_centavos / 100,
            'K' => (float) $slip->days_worked,
            'M' => (int) $slip->late_minutes,
            'P' => (float) $slip->ot_hours,
            'R' => (float) $slip->ot_rest_hours,
            'V' => $slip->sss_centavos / 100,
            'W' => $slip->hdmf_centavos / 100,
            'X' => $slip->cmpeai_centavos / 100,
            'Z' => (float) $slip->absences,
            'AA' => (int) $slip->tardiness,
        ];
        $formulas = [
            'H' => "=ROUND(G{$row}*{$premium}, 2)",
            'I' => "=IF(AND(G{$row}=\"\",H{$row}=\"\"),\"\",G{$row}+H{$row})",
            'L' => "=IF(AND(I{$row}=\"\",K{$row}=\"\"),\"\",I{$row}*K{$row})",
            'N' => "=ROUND(I{$row}/(8*60),2)",
            'O' => "=IF(AND(M{$row}=\"\",N{$row}=\"\"),\"\",M{$row}*N{$row})",
            'Q' => "=ROUND((I{$row}/8)*1.25, 2)",
            'S' => "=ROUND((I{$row}/8)*1.5, 2)",
            'T' => "=IF(E{$row}=\"\",\"\",(P{$row}*Q{$row})+(R{$row}*S{$row}))",
            'U' => "=IF(AND(L{$row}=\"\",O{$row}=\"\",T{$row}=\"\"),\"\",L{$row}-O{$row}+T{$row})",
            'Y' => "=IF(E{$row}=\"\",\"\",ROUND(U{$row}-V{$row}-W{$row}-X{$row},2))",
            'AB' => "=Z{$row}*I{$row}",
        ];

        foreach ($text as $letter => $value) {
            $sheet->getCell("{$letter}{$row}")->setValueExplicit($value, DataType::TYPE_STRING);
        }

        foreach ($numbers as $letter => $value) {
            $sheet->getCell("{$letter}{$row}")->setValueExplicit($value, DataType::TYPE_NUMERIC);
        }

        foreach ($formulas as $letter => $formula) {
            $sheet->getCell("{$letter}{$row}")->setValueExplicit($formula, DataType::TYPE_FORMULA);
        }

        foreach (self::COLUMNS as $letter => [, , $money]) {
            if ($money) {
                $sheet->getStyle("{$letter}{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            }
        }

        $sheet->getStyle("A{$row}:AB{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    /**
     * The grand total under the last worker.
     */
    private function totalRow(Worksheet $sheet, int $row, ?int $lastRow): void
    {
        $sheet->getCell("E{$row}")->setValueExplicit('TOTAL', DataType::TYPE_STRING);

        foreach (self::TOTALS as $letter) {
            $formula = $lastRow === null ? '=0' : "=SUM({$letter}".self::FIRST_ROW.":{$letter}{$lastRow})";
            $sheet->getCell("{$letter}{$row}")->setValueExplicit($formula, DataType::TYPE_FORMULA);
            $sheet->getStyle("{$letter}{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        }

        $sheet->getStyle("A{$row}:AB{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:AB{$row}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_DOUBLE);
    }
}
