<?php

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\PendingImports;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    Storage::fake('local');
    $this->hr = User::factory()->create();
    signIn($this->hr);
    $this->period = PayrollPeriod::factory()->create(['code' => '2026-10-B', 'period_start' => '2026-10-16', 'period_end' => '2026-10-31', 'batch' => '7']);
    $this->employee = Employee::factory()->create(['employee_id' => 'EMP-0001']);
});

/**
 * Download the period's workbook and open it.
 */
function payrollExportSheet(PayrollPeriod $period): array
{
    $response = test()->get("/payroll/{$period->id}/export")->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    return [IOFactory::load($path)->getActiveSheet(), $response];
}

it('exports the payroll in the office workbook layout with its live formulas', function () {
    $slip = Payslip::factory()->create([
        'period_id' => $this->period->id, 'employee_id' => $this->employee->id, 'full_name' => 'Alegre, James Lie O.', 'station' => 'DCCPO',
        'period_covered' => '10/16-31/2026', 'daily_rate_centavos' => 54_000, 'premium_percent' => 15, 'days_worked' => 11, 'late_minutes' => 32,
        'ot_hours' => 2.5, 'ot_rest_hours' => 1, 'sss_centavos' => 75_000, 'hdmf_centavos' => 40_000, 'absences' => 1, 'tardiness' => 6,
    ]);
    app(PayrollService::class)->saveRows($this->period, [['id' => $slip->id, 'values' => ['days' => '11']]]);

    [$sheet, $response] = payrollExportSheet($this->period);

    expect($response->headers->get('Content-Disposition'))->toContain('payroll-2026-10-B.xlsx')
        ->and($sheet->getTitle())->toBe('OCTOBER 2026')
        ->and($sheet->getCell('E1')->getValue())->toBe('PAYROLL OF CONTRACT OF SERVICE (COS)')
        ->and($sheet->getCell('E2')->getValue())->toBe('OCTOBER 16-31, 2026')
        ->and($sheet->getCell('E3')->getValue())->toBe('BATCH 7')
        ->and(array_keys($sheet->getMergeCells()))->toContain('E1:Y1', 'P4:Q4', 'R4:S4')
        ->and($sheet->getCell('P4')->getValue())->toBe('Regular OT')
        ->and($sheet->rangeToArray('A5:AB5')[0])->toBe(['SPECIFIC STATION', 'Period', 'Batch', 'No.', 'Name', 'Official Station', 'Daily Rate', '15% Premium ', 'Total Daily Rate', 'Date Period  Covered', 'No. of Days', 'Gross', 'No. of Late (mins)', 'Rate/min', 'Total Late', 'OT Hours', 'Hourly rate', 'OT Hours RD/SH', 'Hourly rate RD/SH', 'Overtime Pay', 'Gross after Deduction of Late ', 'SSS', 'HDMF', 'CMPEAI', 'Net Pay', 'Absences', 'tardiness', 'Excess'])
        ->and($sheet->getCell('E6')->getValue())->toBe('ALEGRE, JAMES LIE O.')
        ->and($sheet->getCell('B6')->getValue())->toEqual(2)
        ->and($sheet->getCell('H6')->getValue())->toBe('=ROUND(G6*0.15, 2)')
        ->and($sheet->getCell('Y6')->getValue())->toBe('=IF(E6="","",ROUND(U6-V6-W6-X6,2))')
        ->and(round((float) $sheet->getCell('Y6')->getCalculatedValue() * 100))->toEqual($slip->fresh()->net_pay_centavos)
        ->and(round((float) $sheet->getCell('T6')->getCalculatedValue() * 100))->toEqual($slip->fresh()->cosPay()['overtime'])
        ->and($sheet->getCell('E7')->getValue())->toBe('TOTAL')
        ->and($sheet->getCell('Y7')->getValue())->toBe('=SUM(Y6:Y6)');
});

it('writes names as text, so a name can never become a formula', function () {
    Payslip::factory()->create(['period_id' => $this->period->id, 'employee_id' => $this->employee->id, 'full_name' => '=HYPERLINK("x")']);

    [$sheet] = payrollExportSheet($this->period);

    expect($sheet->getCell('E6')->getDataType())->toBe(DataType::TYPE_STRING);
});

it('exports an empty period with a zero total', function () {
    [$sheet] = payrollExportSheet($this->period);

    expect($sheet->getCell('E6')->getValue())->toBe('TOTAL')
        ->and($sheet->getCell('Y6')->getValue())->toBe('=0');
});

it('keeps a staged upload to the person and the period it belongs to', function () {
    $imports = app(PendingImports::class);
    $token = $imports->stage('bytes', $this->hr->id, 'a.xlsx', 'payroll:1');

    expect($imports->claim($token, $this->hr->id + 1, 'payroll:1'))->toBeNull()
        ->and($imports->claim($token, $this->hr->id, 'payroll:2'))->toBeNull()
        ->and($imports->claim('../../secret', $this->hr->id, 'payroll:1'))->toBeNull()
        ->and($imports->claim($token, $this->hr->id, 'payroll:1'))->not->toBeNull()
        ->and($imports->claim($token, $this->hr->id, 'payroll:1'))->toBeNull();

    $imports->release($token);
    expect($imports->claim($token, $this->hr->id, 'payroll:1'))->not->toBeNull();

    $imports->finish($token);
    expect(Storage::disk('local')->files('imports'))->toBe([]);
});
