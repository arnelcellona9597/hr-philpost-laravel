<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Media;
use App\Models\SiteSetting;
use App\Support\EmployeeColumns;
use App\Support\HrDate;
use App\Support\ImportModules;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Table as TableStyle;

/**
 * Employee profiles as Word files (.docx): one bio per employee, or one table of many employees.
 */
class EmployeeWordExporter
{
    private const FONT = 'Calibri';

    private const RULE = '111418';

    private const HEAD_FILL = 'E8EEF8';

    private const BORDER = ['borderSize' => 4, 'borderColor' => '999999'];

    public function __construct(
        private EmploymentHistoryService $history,
        private PayHistoryService $pay,
    ) {}

    /**
     * "EMP-00012 Abuomar, Razul K.docx"
     */
    public function filename(Employee $employee): string
    {
        $name = trim(preg_replace('/[^\w.,\- ]+/u', '', Str::ascii($employee->employee_id.' '.$employee->full_name)));

        return ($name !== '' ? $name : 'employee-'.$employee->id).'.docx';
    }

    /**
     * One employee's profile, laid out like the printed profile.
     */
    public function profile(Employee $employee): string
    {
        return $this->build(function (PhpWord $word) use ($employee): void {
            $site = SiteSetting::current();
            $section = $this->section($word, false);

            $this->letterhead($section, $site);
            $section->addText('EMPLOYEE PROFILE', ['bold' => true, 'size' => 15], ['alignment' => Jc::CENTER, 'spaceBefore' => 160, 'spaceAfter' => 120]);

            $this->summary($section, $employee);

            $fields = collect(ImportModules::find('employees')['fields'])->groupBy('section');

            foreach ($fields as $title => $group) {
                $this->heading($section, (string) $title);
                $this->fieldGrid($section, $employee, $group);
            }

            $this->employment($section, $employee);
            $this->compensation($section, $employee);
            $this->promotions($section, $employee);

            $section->addText('Printed '.HrDate::format(HrDate::today()).'. Confidential: contains personal information.', ['size' => 8, 'color' => '555555'], ['spaceBefore' => 200]);
        });
    }

    /**
     * Many employees in one table, with the columns the person chose.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  list<string>  $columns
     */
    public function table(Collection $employees, array $columns, string $heading = 'EMPLOYEES'): string
    {
        return $this->build(function (PhpWord $word) use ($employees, $columns, $heading): void {
            $choices = EmployeeColumns::available();
            $section = $this->section($word, true);

            $this->letterhead($section, SiteSetting::current());
            $section->addText($heading, ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER, 'spaceBefore' => 120, 'spaceAfter' => 40]);
            $section->addText(number_format($employees->count()).' employee'.($employees->count() === 1 ? '' : 's').' · '.HrDate::format(HrDate::today()), ['size' => 9, 'color' => '555555'], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);

            $table = $section->addTable(['borderSize' => 4, 'borderColor' => '999999', 'cellMargin' => 50, 'alignment' => Jc::CENTER, 'unit' => 'pct', 'width' => 5000]);
            $table->addRow(null, ['tblHeader' => true]);
            $table->addCell(500, ['bgColor' => self::HEAD_FILL])->addText('No.', ['bold' => true, 'size' => 8], ['spaceAfter' => 0]);

            foreach ($columns as $key) {
                $table->addCell(null, ['bgColor' => self::HEAD_FILL])->addText($choices[$key]['label'], ['bold' => true, 'size' => 8], ['spaceAfter' => 0]);
            }

            foreach ($employees->values() as $index => $employee) {
                $table->addRow(null, ['cantSplit' => true]);
                $table->addCell(500)->addText((string) ($index + 1), ['size' => 8], ['spaceAfter' => 0]);

                foreach ($columns as $key) {
                    $table->addCell()->addText(EmployeeColumns::text($employee, $key, $choices[$key]), ['size' => 8], ['spaceAfter' => 0]);
                }
            }
        });
    }

    /**
     * @param  callable(PhpWord): void  $fill
     */
    private function build(callable $fill): string
    {
        $escaping = Settings::isOutputEscapingEnabled();
        Settings::setOutputEscapingEnabled(true);

        try {
            $word = new PhpWord;
            $word->setDefaultFontName(self::FONT);
            $word->setDefaultFontSize(10);
            $word->setDefaultParagraphStyle(['lineHeight' => 1.0, 'spaceAfter' => 0, 'spaceBefore' => 0]);
            $fill($word);

            $path = tempnam(sys_get_temp_dir(), 'employee');
            IOFactory::createWriter($word, 'Word2007')->save($path);
            $bytes = (string) file_get_contents($path);
            @unlink($path);

            return $bytes;
        } finally {
            Settings::setOutputEscapingEnabled($escaping);
        }
    }

    private function section(PhpWord $word, bool $landscape): Section
    {
        return $word->addSection([
            'orientation' => $landscape ? 'landscape' : 'portrait',
            'pageSizeW' => $landscape ? 16838 : 11906,
            'pageSizeH' => $landscape ? 11906 : 16838,
            'marginTop' => 720,
            'marginBottom' => 720,
            'marginLeft' => 900,
            'marginRight' => 900,
        ]);
    }

    /**
     * The office letterhead: wording on the left, logo on the right, a rule and the section bar below.
     */
    private function letterhead(Section $section, SiteSetting $site): void
    {
        $letterhead = $site->letterhead();
        $logo = $letterhead['logoId'] ? Media::query()->find($letterhead['logoId']) : null;
        $hasLogo = $logo !== null && in_array($logo->mime_type, ['image/png', 'image/jpeg'], true) && Storage::disk('local')->exists($logo->path);

        $table = $section->addTable(['unit' => 'pct', 'width' => 5000]);
        $row = $table->addRow();
        $text = $row->addCell($hasLogo ? 3600 : 5000, ['valign' => 'bottom']);
        $text->addText($letterhead['top'], ['size' => 10], ['spaceAfter' => 0]);

        foreach ($letterhead['name'] as $line) {
            $text->addText($line, ['bold' => true, 'size' => 12], ['spaceAfter' => 0]);
        }

        foreach ($letterhead['details'] as $line) {
            $text->addText($line, ['size' => 8.5], ['spaceAfter' => 0]);
        }

        if ($hasLogo) {
            $row->addCell(1400, ['valign' => 'bottom'])->addImage(Storage::disk('local')->path($logo->path), ['height' => 40, 'alignment' => Jc::END]);
        }

        $section->addText('', ['size' => 2], ['borderBottomSize' => 18, 'borderBottomColor' => self::RULE, 'spaceAfter' => 0]);

        if ($letterhead['section'] !== '') {
            $bar = $section->addTable(['unit' => 'pct', 'width' => 5000, 'alignment' => Jc::END])->addRow();
            $bar->addCell(2000)->addText('', ['size' => 2]);
            $bar->addCell(3000, ['bgColor' => self::RULE, 'valign' => 'center'])->addText($letterhead['section'], ['bold' => true, 'size' => 9.5, 'color' => 'FFFFFF'], ['alignment' => Jc::CENTER, 'spaceBefore' => 20, 'spaceAfter' => 20]);
        }
    }

    private function summary(Section $section, Employee $employee): void
    {
        $section->addText(mb_strtoupper($employee->full_name), ['bold' => true, 'size' => 13], ['spaceAfter' => 20, 'borderTopSize' => 4, 'borderTopColor' => '999999', 'spaceBefore' => 60]);
        $section->addText((string) $employee->employee_id, ['size' => 10, 'name' => 'Consolas'], ['spaceAfter' => 20]);
        $section->addText(($employee->position ?: 'No position recorded').' · '.($employee->station?->name ?? 'No station'), ['size' => 10], ['spaceAfter' => 20]);

        $line = $employee->employment_status->value.' · '.$employee->classification->value
            .($employee->length_of_service !== '' ? ' · '.$employee->length_of_service.' in service' : '')
            .($employee->age !== null ? ' · '.$employee->age.' years old' : '');

        $section->addText($line, ['size' => 10], ['spaceAfter' => 60, 'borderBottomSize' => 4, 'borderBottomColor' => '999999']);
    }

    private function heading(Section $section, string $title): void
    {
        $section->addText($title, ['bold' => true, 'size' => 11], ['spaceBefore' => 160, 'spaceAfter' => 60, 'borderBottomSize' => 4, 'borderBottomColor' => '999999']);
    }

    /**
     * Label above value, three to a row; addresses and long text take a whole row.
     *
     * @param  Collection<int, array<string, mixed>>  $fields
     */
    private function fieldGrid(Section $section, Employee $employee, Collection $fields): void
    {
        $table = $section->addTable(['unit' => 'pct', 'width' => 5000, 'cellMargin' => 30, 'layout' => TableStyle::LAYOUT_FIXED]);
        $slot = 0;
        $row = null;

        foreach ($fields as $field) {
            $wide = $field['kind'] === 'textarea' || Str::contains($field['name'], 'address');

            if ($wide || $row === null || $slot === 3) {
                $row = $table->addRow();
                $slot = 0;
            }

            $cell = $row->addCell($wide ? 5000 : 1667, $wide ? ['gridSpan' => 3] : []);
            $cell->addText($field['label'], ['size' => 8, 'color' => '555555'], ['spaceAfter' => 0]);
            $cell->addText($employee->fieldText($field), ['size' => 10], ['spaceAfter' => 60]);

            $slot = $wide ? 3 : $slot + 1;
        }
    }

    private function employment(Section $section, Employee $employee): void
    {
        $this->heading($section, 'Employment history');
        $summary = $this->history->summary($employee);

        if ($summary['rows'] === []) {
            $section->addText('No employment history recorded.');

            return;
        }

        $rows = [];

        foreach ($summary['rows'] as $row) {
            $period = $row['period'];
            $rows[] = [
                (string) $row['number'],
                HrDate::format($period->hired_on),
                $period->separated_on ? HrDate::format($period->separated_on) : ($period->separation_type ? 'Date unknown' : 'Present'),
                HrDate::formatSpan($row['length']) ?: 'Less than a day',
                (string) $period->position,
                (string) $period->station?->name,
                $period->separation_type?->value ?? 'Active',
            ];
        }

        $this->grid($section, ['No.', 'From', 'To', 'Length', 'Position', 'Station', 'Status'], $rows);

        if ($summary['total']) {
            $section->addText('Total service: '.HrDate::formatSpan($summary['total']), ['bold' => true], ['spaceBefore' => 40]);
        }
    }

    private function compensation(Section $section, Employee $employee): void
    {
        $this->heading($section, 'Compensation History');
        $summary = $this->pay->summary($employee);

        if ($summary['rows'] === []) {
            $section->addText('No compensation records.');

            return;
        }

        $this->grid($section, ['Effective', 'Basic rate', 'Premium', 'Total', 'Paid', 'Kind', 'Reference'], array_map(fn (array $row): array => [
            HrDate::format($row['change']->effective_on),
            Money::format($row['change']->amount_centavos),
            $row['change']->premium_label.' · '.Money::format($row['change']->premium_centavos),
            Money::format($row['change']->total_centavos),
            $row['change']->rate_type->value,
            $row['change']->change_type->value,
            (string) $row['change']->reference,
        ], $summary['rows']));
    }

    private function promotions(Section $section, Employee $employee): void
    {
        $this->heading($section, 'Promotions and demotions');
        $changes = $employee->positionChanges()->get();

        if ($changes->isEmpty()) {
            $section->addText('None recorded.');

            return;
        }

        $this->grid($section, ['Effective', 'Kind', 'From', 'To', 'Classification'], $changes->map(fn ($change): array => [
            HrDate::format($change->effective_on),
            $change->change_type->value,
            (string) $change->from_position,
            (string) $change->to_position,
            $change->to_classification ? ($change->from_classification?->value ?? '—').' → '.$change->to_classification->value : '',
        ])->all());
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function grid(Section $section, array $headers, array $rows): void
    {
        $table = $section->addTable([...self::BORDER, 'cellMargin' => 50, 'unit' => 'pct', 'width' => 5000]);
        $table->addRow(null, ['tblHeader' => true]);

        foreach ($headers as $header) {
            $table->addCell(null, ['bgColor' => self::HEAD_FILL])->addText($header, ['bold' => true, 'size' => 8.5], ['spaceAfter' => 0]);
        }

        foreach ($rows as $row) {
            $table->addRow(null, ['cantSplit' => true]);

            foreach ($row as $value) {
                $table->addCell()->addText($value, ['size' => 8.5], ['spaceAfter' => 0]);
            }
        }
    }
}
