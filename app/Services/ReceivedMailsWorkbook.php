<?php

namespace App\Services;

use App\Enums\ImportJobStatus;
use App\Models\ImportJob;
use App\Models\Media;
use App\Models\ReceivedMail;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\ExcelCells;
use App\Support\HrDate;
use App\Support\ZipGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * The received mails log as an Excel workbook, one sheet per month, in the layout the office has used:
 * letterhead, "RECEIVED MAILS", the month, then No., Date, Tracking Number, Post Office, Description,
 * Remarks and Received. The date is written once for each day. Reading finds the header row by the
 * words in it, so the office's own old sheets import as well as this app's exports.
 *
 * @phpstan-type MailRow array{sheet: string, row: int, received_on: string, tracking_number: string, post_office: string, description: string, remarks: string|null, received_by: string|null, exists: bool}
 * @phpstan-type Problem array{sheet: string, row?: int, column?: string, message: string}
 * @phpstan-type Plan array{rows: list<MailRow>, sheets: array<string, array{rows: int, new: int, existing: int}>, errors: list<Problem>, warnings: list<string>}
 */
class ReceivedMailsWorkbook
{
    public const MAX_ROWS = 10_000;

    private const FIRST_DATA_ROW = 17;

    private const HEADER_ROW = 16;

    private const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

    /**
     * One monthly sheet per month asked for, oldest first.
     *
     * @param  list<string>  $months  Each as YYYY-MM
     */
    public function export(array $months): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($months as $month) {
            $this->addSheet($spreadsheet, $month);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    public function filename(string $month): string
    {
        return "received-mails-{$month}.xlsx";
    }

    /**
     * Read every sheet that looks like a mail log. Nothing is saved.
     *
     * @return Plan
     */
    public function analyze(string $path): array
    {
        $plan = ['rows' => [], 'sheets' => [], 'errors' => [], 'warnings' => []];
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

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $this->readSheet($sheet, $date1904, $plan);

            if (count($plan['rows']) > self::MAX_ROWS) {
                return $fail('Too many rows (max '.number_format(self::MAX_ROWS).' per file). Split the file.');
            }
        }

        if ($plan['sheets'] === [] && $plan['errors'] === []) {
            return $fail('No sheet in this file looks like a mail log. A mail log has a header row with "Tracking Number", "Post Office" and "Description".');
        }

        $this->markExisting($plan);

        return $plan;
    }

    /**
     * Save the new rows in one transaction and record the import.
     *
     * @param  Plan  $plan
     * @return array{created: int, existing: int}
     */
    public function commit(array $plan, User $user, string $fileName): array
    {
        $new = array_values(array_filter($plan['rows'], fn (array $row): bool => ! $row['exists']));
        $existing = count($plan['rows']) - count($new);

        DB::transaction(function () use ($new, $user, $fileName, $existing): void {
            foreach (array_chunk($new, 500) as $chunk) {
                ReceivedMail::query()->insert(array_map(fn (array $row): array => [
                    'received_on' => $row['received_on'],
                    'tracking_number' => $row['tracking_number'],
                    'post_office' => $row['post_office'],
                    'description' => $row['description'],
                    'remarks' => $row['remarks'],
                    'received_by' => $row['received_by'],
                    'created_by' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $chunk));
            }

            (new ImportJob([
                'file_name' => $fileName,
                'module' => 'Received Mails',
                'user_id' => $user->id,
                'user_name' => $user->name ?: $user->email,
                'status' => ImportJobStatus::Committed,
                'created' => count($new),
                'updated' => 0,
                'unchanged' => $existing,
            ]))->save();

            AuditTrail::record('import', 'received-mails', $fileName, ['created' => ['from' => null, 'to' => count($new).' mails']]);
        });

        return ['created' => count($new), 'existing' => $existing];
    }

    private function addSheet(Spreadsheet $spreadsheet, string $month): void
    {
        $first = Carbon::parse("{$month}-01");
        $mails = ReceivedMail::query()
            ->whereBetween('received_on', [$first->toDateString(), $first->copy()->endOfMonth()->toDateString()])
            ->orderBy('received_on')
            ->orderBy('id')
            ->get();

        $sheet = $spreadsheet->createSheet()->setTitle(mb_strtoupper($first->format('F Y')));
        $site = SiteSetting::current();

        foreach (['A' => 6, 'B' => 14, 'C' => 22, 'D' => 24, 'L' => 22, 'M' => 20] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        foreach (range('E', 'K') as $column) {
            $sheet->getColumnDimension($column)->setWidth(8);
        }

        $sheet->mergeCells('A1:M10');
        $sheet->getCell('A1')->setValueExplicit("Republic of the Philippines\n".mb_strtoupper($site->company_name).($site->tagline ? "\n{$site->tagline}" : ''), DataType::TYPE_STRING);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $this->addLogo($sheet, $site);

        $sheet->mergeCells('A12:M12');
        $sheet->getCell('A12')->setValueExplicit('RECEIVED MAILS', DataType::TYPE_STRING);
        $sheet->getStyle('A12')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A12')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->mergeCells('A14:M14');
        $sheet->getCell('A14')->setValueExplicit('FOR THE MONTH OF '.mb_strtoupper($first->format('F Y')), DataType::TYPE_STRING);
        $sheet->getStyle('A14')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach (['A' => 'No.', 'B' => 'DATE', 'C' => 'TRACKING NUMBER', 'D' => 'POST OFFICE', 'E' => 'DESCRIPTION', 'L' => 'REMARKS', 'M' => 'Received'] as $column => $label) {
            $sheet->getCell($column.self::HEADER_ROW)->setValueExplicit($label, DataType::TYPE_STRING);
        }

        $sheet->mergeCells('E'.self::HEADER_ROW.':K'.self::HEADER_ROW);
        $header = $sheet->getStyle('A'.self::HEADER_ROW.':M'.self::HEADER_ROW);
        $header->getFont()->setBold(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8EEF8');
        $header->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $row = self::FIRST_DATA_ROW;
        $previous = null;

        foreach ($mails as $index => $mail) {
            $day = $mail->received_on->toDateString();

            $sheet->getCell("A{$row}")->setValueExplicit($index + 1, DataType::TYPE_NUMERIC);

            if ($day !== $previous) {
                $sheet->getCell("B{$row}")->setValueExplicit(ExcelDate::stringToExcel($day), DataType::TYPE_NUMERIC);
                $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                $previous = $day;
            }

            // Text stays text, so a value starting with "=" can never become a formula.
            foreach (['C' => $mail->tracking_number, 'D' => $mail->post_office, 'E' => $mail->description, 'L' => $mail->remarks, 'M' => $mail->received_by] as $column => $value) {
                if ($value !== null && $value !== '') {
                    $sheet->getCell("{$column}{$row}")->setValueExplicit($value, DataType::TYPE_STRING);
                }
            }

            $sheet->mergeCells("E{$row}:K{$row}");
            $sheet->getRowDimension($row)->setRowHeight(15 * max(1, (int) ceil(mb_strlen($mail->description) / 52)));
            $row++;
        }

        $lastRow = max($row - 1, self::FIRST_DATA_ROW);
        $body = $sheet->getStyle('A'.self::HEADER_ROW.":M{$lastRow}");
        $body->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $body->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);

        $sheet->getCell('E'.($lastRow + 2))->setValueExplicit('Total mails received: '.$mails->count(), DataType::TYPE_STRING);
        $sheet->getStyle('E'.($lastRow + 2))->getFont()->setBold(true);
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(self::HEADER_ROW, self::HEADER_ROW);
        $sheet->getSheetView()->setZoomScale(90);
        $sheet->freezePane('A'.self::FIRST_DATA_ROW);
    }

    private function addLogo(Worksheet $sheet, SiteSetting $site): void
    {
        $media = $site->logo_id ? Media::query()->find($site->logo_id) : null;

        if ($media === null || ! in_array($media->mime_type, ['image/png', 'image/jpeg'], true) || ! Storage::disk('local')->exists($media->path)) {
            return;
        }

        $logo = new Drawing;
        $logo->setPath(Storage::disk('local')->path($media->path));
        $logo->setHeight(90);
        $logo->setCoordinates('B2');
        $logo->setWorksheet($sheet);
    }

    /**
     * @param  Plan  $plan
     */
    private function readSheet(Worksheet $sheet, bool $date1904, array &$plan): void
    {
        $title = $sheet->getTitle();
        $columns = $this->findHeader($sheet);

        if ($columns === null) {
            if ($sheet->getSheetState() === Worksheet::SHEETSTATE_VISIBLE && $sheet->getHighestDataRow() > 1) {
                $plan['warnings'][] = "Sheet \"{$title}\" was ignored: it has no header row with \"Tracking Number\".";
            }

            return;
        }

        $missing = array_diff(['tracking_number', 'post_office', 'description'], array_keys($columns['map']));

        if ($missing !== []) {
            $plan['errors'][] = ['sheet' => $title, 'message' => 'Missing the '.implode(', ', array_map(fn (string $name): string => ucwords(str_replace('_', ' ', $name)), $missing)).' column.'];

            return;
        }

        $fallback = $this->monthOf($title);
        $current = null;
        $guessed = false;
        $summary = ['rows' => 0, 'new' => 0, 'existing' => 0];

        for ($row = $columns['row'] + 1, $last = $sheet->getHighestDataRow(); $row <= $last; $row++) {
            $values = array_map(fn (int $column): mixed => ExcelCells::plain($sheet->getCell([$column, $row])), $columns['map']);

            if (ExcelCells::isBlank($values['tracking_number'] ?? null) && ExcelCells::isBlank($values['post_office'] ?? null) && (ExcelCells::isBlank($values['description'] ?? null) || preg_match('/^total\b/i', trim((string) $values['description'])))) {
                continue;
            }

            if (isset($values['date']) && ! ExcelCells::isBlank($values['date'])) {
                [$date, $error] = ExcelCells::date($values['date'], $date1904);

                if ($error !== null) {
                    $plan['errors'][] = ['sheet' => $title, 'row' => $row, 'column' => 'Date', 'message' => $error];

                    continue;
                }

                $current = $date;
            }

            if ($current === null) {
                if ($fallback === null) {
                    $plan['errors'][] = ['sheet' => $title, 'row' => $row, 'column' => 'Date', 'message' => 'has no date above it, and the sheet name has no month'];

                    continue;
                }

                $current = $fallback;
                $guessed = true;
            }

            $entry = $this->entry($values, $current, $title, $row, $plan);

            if ($entry !== null) {
                $plan['rows'][] = $entry;
                $summary['rows']++;
            }
        }

        if ($guessed) {
            $plan['warnings'][] = "Sheet \"{$title}\": the first rows had no date, so they were dated the 1st of the month from the sheet name.";
        }

        $plan['sheets'][$title] = $summary;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  Plan  $plan
     * @return MailRow|null
     */
    private function entry(array $values, string $date, string $sheet, int $row, array &$plan): ?array
    {
        $entry = [
            'sheet' => $sheet,
            'row' => $row,
            'received_on' => $date,
            'tracking_number' => (string) ExcelCells::text($values['tracking_number'] ?? null),
            'post_office' => (string) ExcelCells::text($values['post_office'] ?? null),
            'description' => (string) ExcelCells::text($values['description'] ?? null),
            'remarks' => ExcelCells::text($values['remarks'] ?? null),
            'received_by' => ExcelCells::text($values['received_by'] ?? null),
            'exists' => false,
        ];

        $limits = ['tracking_number' => [60, 'Tracking Number'], 'post_office' => [120, 'Post Office'], 'description' => [2000, 'Description'], 'remarks' => [1000, 'Remarks'], 'received_by' => [120, 'Received']];

        foreach (['tracking_number', 'post_office', 'description'] as $required) {
            if ($entry[$required] === '') {
                $plan['errors'][] = ['sheet' => $sheet, 'row' => $row, 'column' => $limits[$required][1], 'message' => 'is required'];

                return null;
            }
        }

        foreach ($limits as $field => [$max, $label]) {
            if (mb_strlen((string) $entry[$field]) > $max) {
                $plan['errors'][] = ['sheet' => $sheet, 'row' => $row, 'column' => $label, 'message' => "must be at most {$max} characters"];

                return null;
            }
        }

        return $entry;
    }

    /**
     * The header row (within the first 40 rows) and the column of each field.
     *
     * @return array{row: int, map: array<string, int>}|null
     */
    private function findHeader(Worksheet $sheet): ?array
    {
        $lastColumn = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), 30);

        for ($row = 1, $last = min($sheet->getHighestDataRow(), 40); $row <= $last; $row++) {
            $map = [];

            for ($column = 1; $column <= $lastColumn; $column++) {
                $key = ExcelCells::headerKey(ExcelCells::plain($sheet->getCell([$column, $row])));

                $field = match (true) {
                    $key === 'date' => 'date',
                    str_contains($key, 'tracking') => 'tracking_number',
                    str_contains($key, 'post office') => 'post_office',
                    str_contains($key, 'description') => 'description',
                    str_contains($key, 'remark') => 'remarks',
                    str_starts_with($key, 'received') => 'received_by',
                    default => null,
                };

                if ($field !== null && ! isset($map[$field])) {
                    $map[$field] = $column;
                }
            }

            if (isset($map['tracking_number'])) {
                return ['row' => $row, 'map' => $map];
            }
        }

        return null;
    }

    /**
     * The first day of the month a sheet is named for, such as "SEPTEMBER 2026".
     */
    private function monthOf(string $title): ?string
    {
        if (! preg_match('/([a-z]{3,})\W*(\d{4})/i', $title, $found)) {
            return null;
        }

        $month = array_search(true, array_map(fn (string $name): bool => str_starts_with($name, mb_strtolower(mb_substr($found[1], 0, 3))), self::MONTHS), true);

        return $month === false ? null : sprintf('%d-%02d-01', $found[2], $month + 1);
    }

    /**
     * Mark rows that are already in the log, and drop identical rows repeated within the file.
     *
     * @param  Plan  $plan
     */
    private function markExisting(array &$plan): void
    {
        if ($plan['rows'] === []) {
            return;
        }

        $dates = array_column($plan['rows'], 'received_on');
        $key = fn (string $date, string $tracking, string $office, string $description): string => mb_strtolower("{$date}|{$tracking}|{$office}|{$description}");
        $saved = array_flip(ReceivedMail::query()
            ->whereBetween('received_on', [min($dates), max($dates)])
            ->get(['received_on', 'tracking_number', 'post_office', 'description'])
            ->map(fn (ReceivedMail $mail): string => $key($mail->received_on->toDateString(), $mail->tracking_number, $mail->post_office, $mail->description))
            ->all());

        $seen = [];
        $repeated = 0;
        $rows = [];

        foreach ($plan['rows'] as $row) {
            $id = $key($row['received_on'], $row['tracking_number'], $row['post_office'], $row['description']);

            if (isset($seen[$id])) {
                $repeated++;

                continue;
            }

            $seen[$id] = true;
            $row['exists'] = isset($saved[$id]);
            $rows[] = $row;
            $plan['sheets'][$row['sheet']]['new'] += $row['exists'] ? 0 : 1;
            $plan['sheets'][$row['sheet']]['existing'] += $row['exists'] ? 1 : 0;
        }

        $plan['rows'] = $rows;

        if ($repeated > 0) {
            $plan['warnings'][] = "{$repeated} row(s) were exactly the same as an earlier row in the file and will be skipped.";
        }
    }

    /**
     * Today as a month, such as 2026-11, for the picker.
     */
    public static function currentMonth(): string
    {
        return substr(HrDate::today(), 0, 7);
    }
}
