<?php

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\PayrollWorkbook;
use App\Services\PendingImports;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A workbook on disk from rows of cells, the first row being the headers.
 *
 * @param  list<list<mixed>>  $rows
 */
function workbookFile(array $rows): string
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);
    $path = tempnam(sys_get_temp_dir(), 'payroll').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

/**
 * @param  list<list<mixed>>  $rows
 */
function workbookUpload(array $rows): UploadedFile
{
    return new UploadedFile(workbookFile($rows), 'amounts.xlsx', test: true);
}

beforeEach(function () {
    Storage::fake('local');
    $this->hr = User::factory()->create();
    signIn($this->hr);
    $this->period = PayrollPeriod::factory()->create(['code' => '2026-10-A']);
    $this->employee = Employee::factory()->create(['employee_id' => 'EMP-0001']);
});

it('exports the template items and totals', function () {
    Payslip::factory()->create(['period_id' => $this->period->id, 'employee_id' => $this->employee->id, 'employee_code' => 'EMP-0001', 'full_name' => '=HYPERLINK("x")']);

    $response = $this->get("/payroll/{$this->period->id}/export")->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    $sheet = IOFactory::load($path)->getActiveSheet();
    $headers = $sheet->rangeToArray('A1:O1')[0];

    expect($response->headers->get('Content-Disposition'))->toContain('payroll-2026-10-A.xlsx')
        ->and($headers)->toContain('Employee ID', 'Basic Pay', 'Gross Pay', 'Loans', 'Net Pay', 'Remarks')
        ->and($sheet->getCell('A2')->getValue())->toBe('EMP-0001')
        ->and($sheet->getCell('B2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('C2')->getValue())->toEqual(25000);
});

it('imports after a check: blank cells are zero, missing columns stay and new payslips are created', function () {
    $existing = Payslip::factory()->create([
        'period_id' => $this->period->id,
        'employee_id' => $this->employee->id,
        'earnings' => [['label' => 'Basic Pay', 'amount_centavos' => 100], ['label' => 'PERA', 'amount_centavos' => 200]],
        'deductions' => [['label' => 'Loans', 'amount_centavos' => 50]],
    ]);
    $newcomer = Employee::factory()->create(['employee_id' => 'EMP-0002']);

    $check = $this->post("/payroll/{$this->period->id}/import", ['file' => workbookUpload([
        ['Employee ID', 'Basic Pay', 'Remarks'],
        ['emp-0001', '₱1,500.50', 'Paid'],
        ['EMP-0002', null, null],
    ])])->assertOk()->assertSee('1 payslip(s) will be updated and 1 created')->assertSee('left unchanged');

    expect($existing->fresh()->gross_pay_centavos)->toBe(300);

    $this->post("/payroll/{$this->period->id}/import/commit", ['token' => $check->viewData('token')])->assertRedirect("/payroll/{$this->period->id}");

    $existing->refresh();

    expect(array_column($existing->earnings, 'amount_centavos', 'label')['Basic Pay'])->toBe(150_050)
        ->and(array_column($existing->earnings, 'amount_centavos', 'label')['PERA'])->toBe(200)
        ->and($existing->remarks)->toBe('Paid')
        ->and(Payslip::query()->where('employee_id', $newcomer->id)->sole()->gross_pay_centavos)->toBe(0);

    $this->post("/payroll/{$this->period->id}/import/commit", ['token' => $check->viewData('token')])->assertSessionHas('flash.type', 'error');
});

it('lists every problem and saves nothing', function () {
    $response = $this->post("/payroll/{$this->period->id}/import", ['file' => workbookUpload([
        ['Employee ID', 'Basic Pay'],
        ['NOPE', '1'],
        [null, '1'],
        ['EMP-0001', '1'],
        ['EMP-0001', '2'],
    ])])->assertOk();

    expect($response->viewData('plan')['errors'])->toHaveCount(3)
        ->and(Payslip::query()->count())->toBe(0);

    $response->assertSee('no employee with Employee ID')->assertSee('Duplicate of row 4');
});

it('reports amount errors with their Excel row when confirming, and nothing is saved', function () {
    $check = $this->post("/payroll/{$this->period->id}/import", ['file' => workbookUpload([
        ['Employee ID', 'Basic Pay'],
        ['EMP-0001', 'abc'],
    ])]);

    $this->post("/payroll/{$this->period->id}/import/commit", ['token' => $check->viewData('token')])
        ->assertOk()
        ->assertSee('is not an amount');

    expect($check->viewData('plan')['errors'])->toBe([])
        ->and(Payslip::query()->count())->toBe(0);
});

it('uses the saved result of a formula without running it', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([['Employee ID', 'Basic Pay'], ['EMP-0001', '=100+50']]);
    $path = tempnam(sys_get_temp_dir(), 'formula').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $plan = app(PayrollWorkbook::class)->analyze($this->period, $path);

    expect($plan['rows'][0]['values']['e:Basic Pay'])->toEqual('150');
});

it('refuses new payslips on a released period but corrects existing ones', function () {
    $slip = Payslip::factory()->create(['period_id' => $this->period->id, 'employee_id' => $this->employee->id, 'employee_code' => 'EMP-0001']);
    app(PayrollService::class)->release($this->period, $this->hr, false);
    Employee::factory()->create(['employee_id' => 'EMP-0002']);

    $this->post("/payroll/{$this->period->id}/import", ['file' => workbookUpload([['Employee ID', 'Basic Pay'], ['EMP-0002', '1']])])
        ->assertOk()
        ->assertSee('no new payslip can be added');

    $check = $this->post("/payroll/{$this->period->id}/import", ['file' => workbookUpload([['Employee ID', 'Basic Pay'], ['EMP-0001', '30000']])])
        ->assertOk()
        ->assertSee('corrected after release');

    $this->post("/payroll/{$this->period->id}/import/commit", ['token' => $check->viewData('token')]);

    expect($slip->fresh()->corrected_after_release)->toBeTrue()
        ->and($slip->fresh()->gross_pay_centavos)->toBe(3_000_000);
});

it('refuses files that are not workbooks', function () {
    $this->post("/payroll/{$this->period->id}/import", ['file' => UploadedFile::fake()->create('notes.pdf', 10)])->assertSessionHas('flash.type', 'error');

    $this->post("/payroll/{$this->period->id}/import", ['file' => UploadedFile::fake()->createWithContent('fake.xlsx', 'not a zip file at all, just text')])
        ->assertOk()
        ->assertSee('not a valid Excel workbook');
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
