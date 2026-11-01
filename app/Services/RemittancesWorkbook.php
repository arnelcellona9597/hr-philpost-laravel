<?php

namespace App\Services;

use App\Enums\ImportJobStatus;
use App\Enums\RemittanceKind;
use App\Enums\RemittanceStatus;
use App\Models\Employee;
use App\Models\ImportJob;
use App\Models\Media;
use App\Models\Remittance;
use App\Models\RemittanceLine;
use App\Models\RemittanceSetting;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\ExcelCells;
use App\Support\Money;
use App\Support\ZipGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
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
 * Remittances as Excel, laid out like the office's own SSS, Pag-IBIG and CMPEAI sheets, and the other way
 * round: a month is read back from a sheet laid out that way. Totals are real SUM formulas, text is always
 * written as text, and reading uses the header row's words, never fixed cell positions.
 *
 * @phpstan-type Line array{last_name: string, first_name: string, middle_name: string|null, extension: string|null, birth_date: string|null, station: string|null, member_number: string|null, amount_centavos: int, employer_share_centavos: int, compensation_centavos: int, remarks: string|null, employee_id: int|null, row: int}
 * @phpstan-type Month array{year: int, month: int, sheet: string, lines: list<Line>, exists: bool, finalized: bool}
 * @phpstan-type Problem array{sheet: string, row?: int, column?: string, message: string}
 * @phpstan-type Plan array{kind: RemittanceKind, months: array<string, Month>, errors: list<Problem>, warnings: list<string>}
 */
class RemittancesWorkbook
{
    public const MAX_ROWS = 6_000;

    private const MONEY = '#,##0.00';

    private const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

    public function __construct(private readonly RemittanceService $service) {}

    public function export(Remittance $remittance): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet()->setTitle(mb_strtoupper($remittance->kind->shortLabel().' '.$remittance->periodLabel()));
        $setting = RemittanceSetting::forKind($remittance->kind);
        $lines = $remittance->lines()->get();

        match ($remittance->kind) {
            RemittanceKind::Sss => $this->writeSss($sheet, $remittance, $setting, $lines),
            RemittanceKind::Hdmf => $this->writeHdmf($sheet, $remittance, $setting, $lines),
            RemittanceKind::Cmpeai => $this->writeCmpeai($sheet, $remittance, $setting, $lines),
        };

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getSheetView()->setZoomScale(90);

        return $spreadsheet;
    }

    public function filename(Remittance $remittance): string
    {
        return sprintf('%s-remittance-%d-%02d.xlsx', $remittance->kind->value, $remittance->year, $remittance->month);
    }

    /**
     * Read the sheets of a workbook as months of one fund. Nothing is saved.
     *
     * @return Plan
     */
    public function analyze(string $path, RemittanceKind $kind, ?int $fallbackYear = null, ?int $fallbackMonth = null): array
    {
        $plan = ['kind' => $kind, 'months' => [], 'errors' => [], 'warnings' => []];
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
        $matcher = $this->matcher($kind);
        $total = 0;

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $total += $this->readSheet($sheet, $kind, $date1904, $matcher, $fallbackYear, $fallbackMonth, $plan);

            if ($total > self::MAX_ROWS) {
                return $fail('Too many rows (max '.number_format(self::MAX_ROWS).' per file). Split the file.');
            }
        }

        if ($plan['months'] === [] && $plan['errors'] === []) {
            return $fail("No sheet in this file looks like a {$kind->label()} remittance. Check that you chose the right fund, and that the sheet has its header row.");
        }

        foreach ($plan['months'] as $key => $month) {
            $existing = Remittance::query()->where(['kind' => $kind->value, 'year' => $month['year'], 'month' => $month['month']])->first();
            $plan['months'][$key]['exists'] = $existing !== null;
            $plan['months'][$key]['finalized'] = $existing?->isFinalized() ?? false;

            if ($existing?->isFinalized()) {
                $plan['errors'][] = ['sheet' => $month['sheet'], 'message' => $existing->periodLabel().' is finalized in the system. Reopen it first, or leave that sheet out.'];
            }
        }

        return $plan;
    }

    /**
     * Save every month in one transaction. A month already there (and not finalized) is replaced.
     *
     * @param  Plan  $plan
     * @return array{created: int, replaced: int, people: int}
     */
    public function commit(array $plan, User $user, string $fileName): array
    {
        $created = 0;
        $replaced = 0;
        $people = 0;

        DB::transaction(function () use ($plan, $user, $fileName, &$created, &$replaced, &$people): void {
            foreach ($plan['months'] as $month) {
                $remittance = Remittance::query()->where(['kind' => $plan['kind']->value, 'year' => $month['year'], 'month' => $month['month']])->first();

                if ($remittance === null) {
                    $remittance = new Remittance(['kind' => $plan['kind'], 'year' => $month['year'], 'month' => $month['month'], 'status' => RemittanceStatus::Draft]);
                    $remittance->created_by = $user->id;
                    $remittance->save();
                    $created++;
                } else {
                    $remittance->lines()->delete();
                    $replaced++;
                }

                foreach (array_chunk($month['lines'], 500) as $chunk) {
                    RemittanceLine::query()->insert(array_map(fn (array $line, int $index): array => [
                        'remittance_id' => $remittance->id,
                        'employee_id' => $line['employee_id'],
                        'position' => $index + 1,
                        'last_name' => $line['last_name'],
                        'first_name' => $line['first_name'],
                        'middle_name' => $line['middle_name'],
                        'extension' => $line['extension'],
                        'birth_date' => $line['birth_date'],
                        'station' => $line['station'],
                        'member_number' => $line['member_number'],
                        'amount_centavos' => $line['amount_centavos'],
                        'employer_share_centavos' => $line['employer_share_centavos'],
                        'compensation_centavos' => $line['compensation_centavos'],
                        'remarks' => $line['remarks'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], $chunk, array_keys($chunk)));
                }

                $people += count($month['lines']);
            }

            (new ImportJob([
                'file_name' => $fileName,
                'module' => 'Remittances — '.$plan['kind']->label(),
                'user_id' => $user->id,
                'user_name' => $user->name ?: $user->email,
                'status' => ImportJobStatus::Committed,
                'created' => $created,
                'updated' => $replaced,
                'unchanged' => 0,
            ]))->save();

            AuditTrail::record('import', 'remittances', $fileName, ['months' => ['from' => null, 'to' => ($created + $replaced).' '.$plan['kind']->label().' month(s), '.$people.' people']]);
        });

        return ['created' => $created, 'replaced' => $replaced, 'people' => $people];
    }

    /**
     * @param  iterable<RemittanceLine>  $lines
     */
    private function writeSss(Worksheet $sheet, Remittance $remittance, RemittanceSetting $setting, iterable $lines): void
    {
        $row = $this->frame($sheet, $remittance, $setting, 'E');

        foreach (['A' => 8, 'B' => 38, 'C' => 16, 'D' => 20, 'E' => 16] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $header = $row;
        $this->headings($sheet, $header, ['Line No.', 'Job Order / Contractual Name', 'Date of Birth', 'SSS Number', 'Amount']);
        $first = ++$row;

        foreach ($lines as $index => $line) {
            $sheet->getCell("A{$row}")->setValueExplicit($index + 1, DataType::TYPE_NUMERIC);
            $this->text($sheet, "B{$row}", $line->full_name);
            $this->date($sheet, "C{$row}", $line->birth_date?->toDateString());
            $this->text($sheet, "D{$row}", $line->member_number);
            $this->money($sheet, "E{$row}", $line->amount_centavos);
            $row++;
        }

        $last = max($row - 1, $first);
        $this->borders($sheet, "A{$header}:E{$last}");
        $sheet->getCell("D{$row}")->setValueExplicit('TOTAL', DataType::TYPE_STRING);
        $this->total($sheet, "E{$row}", $row > $first ? "=SUM(E{$first}:E{$last})" : 0);
        $sheet->getStyle("D{$row}")->getFont()->setBold(true);
        $this->signatures($sheet, $row + 3, $setting, 'B', 'D');
    }

    /**
     * @param  iterable<RemittanceLine>  $lines
     */
    private function writeHdmf(Worksheet $sheet, Remittance $remittance, RemittanceSetting $setting, iterable $lines): void
    {
        $row = $this->frame($sheet, $remittance, $setting, 'L');

        foreach (['A' => 20, 'B' => 14, 'C' => 16, 'D' => 20, 'E' => 20, 'F' => 10, 'G' => 18, 'H' => 10, 'I' => 16, 'J' => 14, 'K' => 14, 'L' => 24] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        foreach ([['Employer ID', $setting->employer_id], ['Employer Name', $setting->employer_name], ['Employer Address', $setting->employer_address], ['Contact Details', $setting->employer_contact], ['Form Code', $setting->form_code]] as [$label, $value]) {
            $this->text($sheet, "A{$row}", $label);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $this->text($sheet, "B{$row}", $value);
            $row++;
        }

        $row++;
        $header = $row;
        $this->headings($sheet, $header, ['Pag-IBIG ID/RTN', 'Account No.', 'Membership Program', 'Last Name', 'First Name', 'Name Extension', 'Middle Name', 'PERCOV', 'Monthly Compensation', 'ER Share', 'EE Share', 'Remarks']);
        $first = ++$row;
        $percov = sprintf('%04d%02d', $remittance->year, $remittance->month);

        foreach ($lines as $line) {
            $this->text($sheet, "A{$row}", $line->member_number);
            $this->text($sheet, "C{$row}", $setting->membership_program);
            $this->text($sheet, "D{$row}", $line->last_name);
            $this->text($sheet, "E{$row}", $line->first_name);
            $this->text($sheet, "F{$row}", $line->extension);
            $this->text($sheet, "G{$row}", $line->middle_name);
            $this->text($sheet, "H{$row}", $percov);
            $this->money($sheet, "I{$row}", $line->compensation_centavos);
            $this->money($sheet, "J{$row}", $line->employer_share_centavos);
            $this->money($sheet, "K{$row}", $line->amount_centavos);
            $this->text($sheet, "L{$row}", $line->remarks);
            $row++;
        }

        $last = max($row - 1, $first);
        $this->borders($sheet, "A{$header}:L{$last}");
        $sheet->getCell("H{$row}")->setValueExplicit('SUBTOTAL', DataType::TYPE_STRING);
        $sheet->getStyle("H{$row}")->getFont()->setBold(true);

        foreach (['I', 'J', 'K'] as $column) {
            $this->total($sheet, "{$column}{$row}", $row > $first ? "=SUM({$column}{$first}:{$column}{$last})" : 0);
        }

        if ($setting->certification_text) {
            $sheet->mergeCells('A'.($row + 2).':L'.($row + 3));
            $this->text($sheet, 'A'.($row + 2), $setting->certification_text);
            $sheet->getStyle('A'.($row + 2))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        }

        $this->signatures($sheet, $row + 6, $setting, 'A', 'G');
    }

    /**
     * @param  iterable<RemittanceLine>  $lines
     */
    private function writeCmpeai(Worksheet $sheet, Remittance $remittance, RemittanceSetting $setting, iterable $lines): void
    {
        $row = $this->frame($sheet, $remittance, $setting, 'D');

        foreach (['A' => 8, 'B' => 40, 'C' => 28, 'D' => 18] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $header = $row;
        $this->headings($sheet, $header, ['NO.', 'NAME', 'STATION', 'TOTAL PAYMENT']);
        $first = ++$row;

        foreach ($lines as $index => $line) {
            $sheet->getCell("A{$row}")->setValueExplicit($index + 1, DataType::TYPE_NUMERIC);
            $this->text($sheet, "B{$row}", $line->full_name);
            $this->text($sheet, "C{$row}", $line->station);
            $this->money($sheet, "D{$row}", $line->amount_centavos);
            $row++;
        }

        $last = max($row - 1, $first);
        $this->borders($sheet, "A{$header}:D{$last}");
        $this->text($sheet, "C{$row}", 'GROSS TOTAL');
        $this->total($sheet, "D{$row}", $row > $first ? "=SUM(D{$first}:D{$last})" : 0);
        $gross = $row++;
        $this->text($sheet, "C{$row}", 'LESS: SERVICE CHARGE ('.rtrim(rtrim(number_format($setting->service_charge_basis_points / 100, 2), '0'), '.').'%)');
        $this->total($sheet, "D{$row}", '=ROUND(D'.$gross.'*'.($setting->service_charge_basis_points / 10_000).',2)');
        $charge = $row++;
        $this->text($sheet, "C{$row}", 'NET AMOUNT');
        $this->total($sheet, "D{$row}", "=D{$gross}-D{$charge}");

        foreach ([$gross, $charge, $row] as $bold) {
            $sheet->getStyle("C{$bold}")->getFont()->setBold(true);
        }

        $this->signatures($sheet, $row + 3, $setting, 'B', 'C');
    }

    /**
     * Letterhead, logo, title and month. Returns the first free row.
     */
    private function frame(Worksheet $sheet, Remittance $remittance, RemittanceSetting $setting, string $lastColumn): int
    {
        $site = SiteSetting::current();
        $letterhead = trim((string) $setting->letterhead_lines) !== '' ? trim((string) $setting->letterhead_lines) : mb_strtoupper($site->company_name).($site->tagline ? "\n{$site->tagline}" : '');

        $sheet->mergeCells("A1:{$lastColumn}5");
        $sheet->getCell('A1')->setValueExplicit("Republic of the Philippines\n{$letterhead}", DataType::TYPE_STRING);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(11);
        $this->logo($sheet, $site);

        $sheet->mergeCells("A7:{$lastColumn}7");
        $this->text($sheet, 'A7', mb_strtoupper($setting->title));
        $sheet->getStyle('A7')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->mergeCells("A8:{$lastColumn}8");
        $this->text($sheet, 'A8', 'FOR THE MONTH OF '.mb_strtoupper($remittance->periodLabel()));
        $sheet->getStyle('A8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        return 10;
    }

    private function logo(Worksheet $sheet, SiteSetting $site): void
    {
        $media = $site->logo_id ? Media::query()->find($site->logo_id) : null;

        if ($media === null || ! in_array($media->mime_type, ['image/png', 'image/jpeg'], true) || ! Storage::disk('local')->exists($media->path)) {
            return;
        }

        $logo = new Drawing;
        $logo->setPath(Storage::disk('local')->path($media->path));
        $logo->setHeight(70);
        $logo->setCoordinates('A1');
        $logo->setOffsetX(6);
        $logo->setWorksheet($sheet);
    }

    /**
     * @param  list<string>  $labels
     */
    private function headings(Worksheet $sheet, int $row, array $labels): void
    {
        foreach ($labels as $index => $label) {
            $this->text($sheet, [$index + 1, $row], $label);
        }

        $range = 'A'.$row.':'.Coordinate::stringFromColumnIndex(count($labels)).$row;
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8EEF8');
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    }

    private function signatures(Worksheet $sheet, int $row, RemittanceSetting $setting, string $preparedColumn, string $notedColumn): void
    {
        foreach ([[$preparedColumn, 'Prepared by:', $setting->prepared_by_name, $setting->prepared_by_title], [$notedColumn, 'Noted by:', $setting->noted_by_name, $setting->noted_by_title]] as [$column, $label, $name, $title]) {
            $this->text($sheet, "{$column}{$row}", $label);
            $this->text($sheet, $column.($row + 2), $name !== null ? mb_strtoupper($name) : null);
            $sheet->getStyle($column.($row + 2))->getFont()->setBold(true);
            $this->text($sheet, $column.($row + 3), $title);
        }
    }

    private function borders(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }

    /**
     * Text stays text, so a value starting with "=" can never become a formula.
     *
     * @param  string|array{0: int, 1: int}  $cell
     */
    private function text(Worksheet $sheet, string|array $cell, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $sheet->getCell($cell)->setValueExplicit($value, DataType::TYPE_STRING);
        }
    }

    private function date(Worksheet $sheet, string $cell, ?string $ymd): void
    {
        if ($ymd !== null) {
            $sheet->getCell($cell)->setValueExplicit(ExcelDate::stringToExcel($ymd), DataType::TYPE_NUMERIC);
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        }
    }

    private function money(Worksheet $sheet, string $cell, int $centavos): void
    {
        $sheet->getCell($cell)->setValueExplicit($centavos / 100, DataType::TYPE_NUMERIC);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::MONEY);
    }

    private function total(Worksheet $sheet, string $cell, string|int $value): void
    {
        if (is_string($value)) {
            $sheet->getCell($cell)->setValueExplicit($value, DataType::TYPE_FORMULA);
        } else {
            $sheet->getCell($cell)->setValueExplicit($value, DataType::TYPE_NUMERIC);
        }

        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getStyle($cell)->getFont()->setBold(true);
    }

    /**
     * @param  array{byMember: array<string, int|null>, byName: array<string, int|null>}  $matcher
     * @param  Plan  $plan
     * @return int rows read
     */
    private function readSheet(Worksheet $sheet, RemittanceKind $kind, bool $date1904, array $matcher, ?int $fallbackYear, ?int $fallbackMonth, array &$plan): int
    {
        $title = $sheet->getTitle();
        $columns = $this->findHeader($sheet, $kind);

        if ($columns === null) {
            if ($sheet->getSheetState() === Worksheet::SHEETSTATE_VISIBLE && $sheet->getHighestDataRow() > 1) {
                $plan['warnings'][] = "Sheet \"{$title}\" was ignored: it has no {$kind->label()} header row.";
            }

            return 0;
        }

        $lines = [];
        $percov = null;

        for ($row = $columns['row'] + 1, $last = $sheet->getHighestDataRow(); $row <= $last; $row++) {
            $values = array_map(fn (int $column): mixed => ExcelCells::plain($sheet->getCell([$column, $row])), $columns['map']);

            try {
                $line = $this->line($values, $kind, $date1904, $matcher, $row);
            } catch (InvalidArgumentException $exception) {
                $plan['errors'][] = ['sheet' => $title, 'row' => $row, 'message' => $exception->getMessage()];

                continue;
            }

            if ($line === null) {
                continue;
            }

            $percov ??= isset($values['percov']) && preg_match('/^(\d{4})(\d{2})$/', trim((string) $values['percov']), $found) ? [(int) $found[1], (int) $found[2]] : null;
            $lines[] = $line;
        }

        if ($lines === []) {
            $plan['warnings'][] = "Sheet \"{$title}\" has a header but no people, so it was ignored.";

            return 0;
        }

        $month = $this->monthOf($title) ?? $percov ?? $this->monthOfHeading($sheet, $columns['row']) ?? ($fallbackYear !== null && $fallbackMonth !== null ? [$fallbackYear, $fallbackMonth] : null);

        if ($month === null) {
            $plan['errors'][] = ['sheet' => $title, 'message' => 'Cannot tell which month this sheet is for. Name the sheet like "OCTOBER 2026", or choose the month when you upload.'];

            return count($lines);
        }

        [$year, $number] = $month;
        $key = sprintf('%04d-%02d', $year, $number);

        if (isset($plan['months'][$key])) {
            $plan['errors'][] = ['sheet' => $title, 'message' => 'Another sheet in this file is already for '.(new Remittance(['year' => $year, 'month' => $number]))->periodLabel().'.'];

            return count($lines);
        }

        $plan['months'][$key] = ['year' => $year, 'month' => $number, 'sheet' => $title, 'lines' => $lines, 'exists' => false, 'finalized' => false];

        $unnamed = count(array_filter($lines, fn (array $line): bool => $line['employee_id'] === null));

        if ($unnamed > 0) {
            $plan['warnings'][] = "Sheet \"{$title}\": {$unnamed} of ".count($lines).' people are not matched to an employee record. They are kept as listed.';
        }

        return count($lines);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array{byMember: array<string, int|null>, byName: array<string, int|null>}  $matcher
     * @return Line|null
     *
     * @throws InvalidArgumentException with a message naming the problem
     */
    private function line(array $values, RemittanceKind $kind, bool $date1904, array $matcher, int $row): ?array
    {
        if ($kind === RemittanceKind::Hdmf) {
            $last = ExcelCells::text($values['last_name'] ?? null);
            $first = ExcelCells::text($values['first_name'] ?? null);
            $middle = ExcelCells::text($values['middle_name'] ?? null);
            $extension = ExcelCells::text($values['extension'] ?? null);
        } else {
            $name = ExcelCells::text($values['name'] ?? null);

            if ($name === null || preg_match('/^(total|sub-?total|gross|less|net|prepared|noted|certified|approved)\b/i', $name)) {
                return null;
            }

            [$last, $first, $middle, $extension] = $this->splitName($name);
        }

        $member = ExcelCells::text($values['member_number'] ?? null);
        $amount = $this->centavos($values['amount'] ?? null, $kind->amountLabel());

        // Signature blocks under the table have names and titles but no amount, and no number with digits in it.
        $looksLikePerson = ! ExcelCells::isBlank($values['amount'] ?? null) || ($member !== null && preg_match('/\d/', $member) === 1);

        if (($last === null && $first === null) || ! $looksLikePerson) {
            return null;
        }

        if ($last === null || $first === null) {
            throw new InvalidArgumentException('needs both a last name and a first name.');
        }

        $birth = null;

        if (isset($values['birth_date']) && ! ExcelCells::isBlank($values['birth_date'])) {
            [$birth, $error] = ExcelCells::date($values['birth_date'], $date1904);

            if ($error !== null) {
                throw new InvalidArgumentException('Date of birth '.$error.'.');
            }
        }

        $member = $member === null ? null : preg_replace('/\s+/', '', $member);

        foreach (['last_name' => [$last, 80], 'first_name' => [$first, 80], 'member number' => [$member ?? '', 30]] as $label => [$text, $max]) {
            if (mb_strlen((string) $text) > $max) {
                throw new InvalidArgumentException(str_replace('_', ' ', $label)." is longer than {$max} characters.");
            }
        }

        return [
            'last_name' => $last,
            'first_name' => $first,
            'middle_name' => $middle,
            'extension' => $extension,
            'birth_date' => $birth,
            'station' => mb_substr((string) ExcelCells::text($values['station'] ?? null), 0, 120) ?: null,
            'member_number' => $member,
            'amount_centavos' => $amount,
            'employer_share_centavos' => $this->centavos($values['employer_share'] ?? null, 'Employer share'),
            'compensation_centavos' => $this->centavos($values['compensation'] ?? null, 'Monthly compensation'),
            'remarks' => mb_substr((string) ExcelCells::text($values['remarks'] ?? null), 0, 255) ?: null,
            'employee_id' => $this->match($member, $last, $first, $matcher),
            'row' => $row,
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    private function centavos(mixed $value, string $label): int
    {
        if (ExcelCells::isBlank($value)) {
            return 0;
        }

        try {
            return Money::toCentavos(is_float($value) ? round($value, 2) : $value);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException("{$label} {$exception->getMessage()}.");
        }
    }

    /**
     * "DELA CRUZ, JUAN P. JR." into last name, first name, middle name and extension.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: string|null}
     */
    private function splitName(string $name): array
    {
        if (! str_contains($name, ',')) {
            return [$name, null, null, null];
        }

        [$last, $rest] = array_map(trim(...), explode(',', $name, 2));
        $words = preg_split('/\s+/', $rest) ?: [];
        $extension = null;

        if ($words !== [] && preg_match('/^(jr|sr|ii|iii|iv|v)\.?$/i', end($words))) {
            $extension = array_pop($words);
        }

        $middle = null;

        if (count($words) > 1 && preg_match('/^[a-z]\.?$/iu', end($words))) {
            $middle = rtrim((string) array_pop($words), '.');
        }

        return [$last ?: null, implode(' ', $words) ?: null, $middle, $extension];
    }

    /**
     * @param  array{byMember: array<string, int|null>, byName: array<string, int|null>}  $matcher
     */
    private function match(?string $member, string $last, string $first, array $matcher): ?int
    {
        if ($member !== null && array_key_exists($member, $matcher['byMember'])) {
            return $matcher['byMember'][$member];
        }

        return $matcher['byName'][mb_strtolower("{$last}|{$first}")] ?? null;
    }

    /**
     * Employees by member number and by name; a number or name shared by two people matches nobody.
     *
     * @return array{byMember: array<string, int|null>, byName: array<string, int|null>}
     */
    private function matcher(RemittanceKind $kind): array
    {
        $field = $kind->memberField();
        $byMember = [];
        $byName = [];

        Employee::query()->select(array_filter(['id', 'last_name', 'first_name', $field]))->orderBy('id')->chunk(1000, function ($employees) use ($field, &$byMember, &$byName): void {
            foreach ($employees as $employee) {
                $name = mb_strtolower("{$employee->last_name}|{$employee->first_name}");
                $byName[$name] = array_key_exists($name, $byName) ? null : $employee->id;

                $number = $field === null ? null : preg_replace('/\s+/', '', (string) $employee->{$field});

                if ($number !== null && $number !== '') {
                    $byMember[$number] = array_key_exists($number, $byMember) ? null : $employee->id;
                }
            }
        });

        return ['byMember' => $byMember, 'byName' => $byName];
    }

    /**
     * @return array{row: int, map: array<string, int>}|null
     */
    private function findHeader(Worksheet $sheet, RemittanceKind $kind): ?array
    {
        $lastColumn = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), 30);

        for ($row = 1, $last = min($sheet->getHighestDataRow(), 45); $row <= $last; $row++) {
            $map = [];

            for ($column = 1; $column <= $lastColumn; $column++) {
                $key = preg_replace('/[^a-z0-9]+/', '', mb_strtolower((string) ExcelCells::plain($sheet->getCell([$column, $row])))) ?? '';

                if ($key === '') {
                    continue;
                }

                $field = $this->fieldFor($key, $kind);

                if ($field !== null && ! isset($map[$field])) {
                    $map[$field] = $column;
                }
            }

            $found = match ($kind) {
                RemittanceKind::Sss => isset($map['name'], $map['amount']),
                RemittanceKind::Hdmf => isset($map['last_name'], $map['first_name']),
                RemittanceKind::Cmpeai => isset($map['name'], $map['amount']),
            };

            if ($found) {
                return ['row' => $row, 'map' => $map];
            }
        }

        return null;
    }

    private function fieldFor(string $key, RemittanceKind $kind): ?string
    {
        if ($kind === RemittanceKind::Hdmf) {
            return match (true) {
                str_contains($key, 'rtn') || str_contains($key, 'pagibigid') || $key === 'mid' || $key === 'pagibigno' => 'member_number',
                $key === 'lastname' => 'last_name',
                $key === 'firstname' => 'first_name',
                $key === 'middlename' => 'middle_name',
                str_contains($key, 'extension') => 'extension',
                $key === 'percov' => 'percov',
                str_contains($key, 'compensation') => 'compensation',
                $key === 'er' || str_starts_with($key, 'ershare') || str_starts_with($key, 'employershare') => 'employer_share',
                $key === 'ee' || str_starts_with($key, 'eeshare') || str_starts_with($key, 'employeeshare') => 'amount',
                str_starts_with($key, 'remark') => 'remarks',
                default => null,
            };
        }

        return match (true) {
            str_contains($key, 'name') => 'name',
            str_contains($key, 'birth') || $key === 'dob' => 'birth_date',
            $kind === RemittanceKind::Sss && str_contains($key, 'sss') => 'member_number',
            $kind === RemittanceKind::Cmpeai && str_contains($key, 'station') => 'station',
            str_contains($key, 'amount') || str_contains($key, 'payment') || str_contains($key, 'contribution') => 'amount',
            str_starts_with($key, 'remark') => 'remarks',
            default => null,
        };
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function monthOf(string $text): ?array
    {
        if (! preg_match('/([a-z]{3,})\W*(\d{4})/i', $text, $found)) {
            return null;
        }

        $month = array_search(true, array_map(fn (string $name): bool => str_starts_with($name, mb_strtolower(mb_substr($found[1], 0, 3))), self::MONTHS), true);

        return $month === false ? null : [(int) $found[2], $month + 1];
    }

    /**
     * "FOR THE MONTH OF OCTOBER 2026" above the header row.
     *
     * @return array{0: int, 1: int}|null
     */
    private function monthOfHeading(Worksheet $sheet, int $headerRow): ?array
    {
        for ($row = 1; $row < $headerRow; $row++) {
            for ($column = 1, $last = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), 30); $column <= $last; $column++) {
                $text = (string) ExcelCells::plain($sheet->getCell([$column, $row]));

                if (preg_match('/month of|period|covered/i', $text) && ($month = $this->monthOf($text)) !== null) {
                    return $month;
                }
            }
        }

        return null;
    }
}
