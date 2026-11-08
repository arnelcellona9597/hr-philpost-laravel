<?php

use App\Enums\EmploymentStatus;
use App\Enums\ImportJobStatus;
use App\Enums\LeaveStatus;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchUnit;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\ImportJob;
use App\Models\IpcrRating;
use App\Models\ItrSubmission;
use App\Models\Message;
use App\Models\User;
use App\Models\WellnessLeave;
use App\Services\DataImporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * @param  array<string, list<list<mixed>>>  $sheets  Rows per sheet title, the first row being the headers
 */
function dataWorkbook(array $sheets): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->removeSheetByIndex(0);

    foreach ($sheets as $title => $rows) {
        $sheet = $spreadsheet->createSheet()->setTitle($title);

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                if ($value !== null) {
                    $sheet->getCell([$columnIndex + 1, $rowIndex + 1])->setValueExplicit($value, is_string($value) ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC);
                }
            }
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'data').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'hr-data.xlsx', test: true);
}

function employeeRow(array $overrides = []): array
{
    return array_replace(['EMP-1', 'Reyes', 'Ana', 'Female', 'Regular', 'Active'], $overrides);
}

const EMPLOYEE_HEADERS = ['Employee ID', 'Last Name', 'First Name', 'Gender', 'Classification', 'Employment Status'];

function importFile(UploadedFile $file, ?string $module = null)
{
    return test()->post('/data/import', ['file' => $file, 'module' => $module]);
}

beforeEach(function () {
    Storage::fake('local');
    $this->hr = User::factory()->create();
    signIn($this->hr);
});

it('previews without saving, then commits everything and keeps the history', function () {
    $check = importFile(dataWorkbook([
        'Branches' => [['Branch Name', 'Code'], ['Main Office', 'MO']],
        'Employees' => [[...EMPLOYEE_HEADERS, 'Branch'], [...employeeRow(), 'Main Office']],
    ]))->assertOk()->assertSee('All rows passed every check');

    expect(Employee::query()->count())->toBe(0)->and(Branch::query()->count())->toBe(0);

    $this->post('/data/import/commit', ['token' => $check->viewData('token')])->assertRedirect('/data');

    $employee = Employee::query()->sole();
    $job = ImportJob::query()->sole();

    expect($employee->station->name)->toBe('Main Office')
        ->and($job->status)->toBe(ImportJobStatus::Committed)
        ->and($job->created)->toBe(2)
        ->and($job->user_id)->toBe($this->hr->id)
        ->and(AuditLog::query()->where('action', 'import')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'create')->where('collection_slug', 'employees')->count())->toBe(0);

    Storage::disk('local')->assertExists("imports/{$job->archive_file}");

    $this->post('/data/import/commit', ['token' => $check->viewData('token')])->assertSessionHas('flash.type', 'error');
});

it('updates matched records, leaves missing columns alone and reports unchanged rows', function () {
    $employee = Employee::factory()->create(['employee_id' => 'EMP-1', 'position' => 'Clerk', 'first_name' => 'Ana', 'last_name' => 'Reyes', 'gender' => 'Female', 'classification' => 'Regular', 'employment_status' => 'Active', 'remarks' => 'Keep']);

    $unchanged = importFile(dataWorkbook(['Employees' => [[...EMPLOYEE_HEADERS, 'Position / Designation'], [...employeeRow(), 'Clerk']]]));
    $unchanged->assertSee('Nothing to import');

    $check = importFile(dataWorkbook(['Employees' => [[...EMPLOYEE_HEADERS, 'Position / Designation'], [...employeeRow(), 'Director']]]));
    $check->assertSee('Director');

    $this->post('/data/import/commit', ['token' => $check->viewData('token')]);

    expect($employee->fresh()->position)->toBe('Director')->and($employee->fresh()->remarks)->toBe('Keep');
});

it('clears a value with a blank cell', function () {
    $employee = Employee::factory()->create(['employee_id' => 'EMP-1', 'position' => 'Clerk', 'gender' => 'Female', 'classification' => 'Regular', 'employment_status' => 'Active']);

    $check = importFile(dataWorkbook(['Employees' => [[...EMPLOYEE_HEADERS, 'Position / Designation', 'Religion'], [...employeeRow(), null, 'X']]]));
    $this->post('/data/import/commit', ['token' => $check->viewData('token')]);

    expect($employee->fresh()->position)->toBeNull();
});

it('matches lists case-insensitively and reads real, serial and named dates', function () {
    $check = importFile(dataWorkbook(['Employees' => [
        [...EMPLOYEE_HEADERS, 'Date of Birth', 'Date Hired'],
        [...employeeRow(['EMP-1', 'Reyes', 'Ana', 'female', 'regular', 'ACTIVE']), 33000, 'Mar 5, 2024'],
        [...employeeRow(['EMP-2', 'Cruz', 'Ben', 'male']), '1990-02-03', '5 March 2024'],
    ]]))->assertSee('All rows passed');

    $this->post('/data/import/commit', ['token' => $check->viewData('token')]);

    $first = Employee::query()->where('employee_id', 'EMP-1')->sole();

    expect($first->employment_status)->toBe(EmploymentStatus::Active)
        ->and($first->date_of_birth->toDateString())->toBe('1990-05-07')
        ->and($first->date_hired->toDateString())->toBe('2024-03-05')
        ->and(Employee::query()->where('employee_id', 'EMP-2')->sole()->date_of_birth->toDateString())->toBe('1990-02-03');
});

it('lists every cell problem and saves nothing', function (array $row, string $message) {
    $response = importFile(dataWorkbook(['Employees' => [[...EMPLOYEE_HEADERS, 'Date Hired', 'SSS Number', 'Branch'], $row]]))->assertOk();

    $response->assertSee($message, false);

    expect($response->viewData('plan')['errors'])->not->toBeEmpty()->and(Employee::query()->count())->toBe(0);
})->with([
    'required' => [[...employeeRow(['EMP-1', '']), null, null, null], 'is required'],
    'select' => [[...employeeRow(['EMP-1', 'Reyes', 'Ana', 'Nope']), null, null, null], 'is not one of: Male, Female'],
    'ambiguous date' => [[...employeeRow(), '03/05/2024', null, null], 'is ambiguous (day/month order)'],
    'bad date' => [[...employeeRow(), 'soon', null, null], 'is not a recognised date'],
    'not a real date' => [[...employeeRow(), '2026-02-30', null, null], 'is not a real date'],
    'gov id hint' => [[...employeeRow(), null, 123, null], 'Excel may have dropped a leading zero'],
    'branch' => [[...employeeRow(), null, null, 'Nowhere'], 'does not exist'],
]);

it('refuses duplicate rows, repeated columns and missing required columns', function () {
    importFile(dataWorkbook(['Employees' => [EMPLOYEE_HEADERS, employeeRow(), employeeRow()]]))->assertSee('Duplicate of row 2');
    importFile(dataWorkbook(['Employees' => [[...EMPLOYEE_HEADERS, 'Gender'], [...employeeRow(), 'Male']]]))->assertSee('This column appears twice.');
    importFile(dataWorkbook(['Employees' => [['Employee ID', 'Last Name'], ['EMP-1', 'Reyes']]]))->assertSee('Missing required column(s)');

    expect(Employee::query()->count())->toBe(0);
});

it('warns about unknown sheets and columns, and skips the README', function () {
    $response = importFile(dataWorkbook([
        'README' => [['Anything']],
        'Notes' => [['x']],
        'Holidays' => [['Date', 'Name', 'Type', 'Extra'], ['2026-12-25', 'Christmas Day', 'Regular Holiday', 'y']],
    ]))->assertOk();

    expect(implode(' ', $response->viewData('plan')['warnings']))->toContain('Sheet "Notes" was ignored')->toContain('column "Extra" is not recognised')
        ->and(array_column($response->viewData('plan')['sheets'], 'sheet'))->toBe(['Holidays']);
});

it('uses the chosen module for a single sheet with another name', function () {
    importFile(dataWorkbook(['Sheet1' => [['Branch Name'], ['Main']]]), 'branches')->assertSee('All rows passed');
});

it('rehearses business rules without leaving anything behind', function () {
    $employee = Employee::factory()->create(['employee_id' => 'EMP-1']);

    $response = importFile(dataWorkbook(['Wellness Leave' => [
        ['Employee ID', 'Date Filing', 'Inclusive Date From', 'Inclusive Date To', 'Status'],
        ['EMP-1', '2026-02-27', '2026-03-02', '2026-03-06', 'Approved'],
        ['EMP-1', '2026-03-09', '2026-03-10', '2026-03-10', 'Approved'],
    ]]))->assertOk();

    expect($response->viewData('plan')['errors'][0]['row'])->toBe(3)
        ->and(WellnessLeave::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'import')->count())->toBe(0);
});

it('applies leave in date order so allowances are checked correctly', function () {
    Employee::factory()->create(['employee_id' => 'EMP-1']);

    $check = importFile(dataWorkbook(['Wellness Leave' => [
        ['Employee ID', 'Date Filing', 'Inclusive Date From', 'Inclusive Date To', 'Status'],
        ['EMP-1', '2026-03-09', '2026-03-10', '2026-03-10', 'Approved'],
        ['EMP-1', '2026-02-27', '2026-03-02', '2026-03-03', 'Approved'],
    ]]))->assertSee('All rows passed');

    $this->post('/data/import/commit', ['token' => $check->viewData('token')]);

    expect(WellnessLeave::query()->orderBy('id')->pluck('inclusive_date_from')->map->toDateString()->all())->toBe(['2026-03-02', '2026-03-10'])
        ->and(WellnessLeave::query()->first()->status)->toBe(LeaveStatus::Approved)
        ->and(WellnessLeave::query()->first()->days)->toBe(2);
});

it('imports sends no email', function () {
    $branch = Branch::factory()->create(['name' => 'Main']);
    Employee::factory()->create(['employee_id' => 'EMP-1', 'email' => 'ana@example.test']);

    $check = importFile(dataWorkbook([
        'Onboarding' => [['Application ID', 'Applicant Name', 'Email Address', 'Position Applied', 'Station', 'Date Applied', 'Application Status'], ['APP-2026-001', 'Ana Reyes', 'ana@example.test', 'Clerk', 'Main', '2026-01-01', 'Hired']],
        'Wellness Leave' => [['Employee ID', 'Date Filing', 'Inclusive Date From', 'Inclusive Date To', 'Status'], ['EMP-1', '2026-03-01', '2026-03-02', '2026-03-02', 'Approved']],
    ]))->assertSee('All rows passed');

    $this->post('/data/import/commit', ['token' => $check->viewData('token')]);

    expect(Application::query()->sole()->station_id)->toBe($branch->id)
        ->and(Message::query()->count())->toBe(0);
});

it('links requirements to employees added by the same file and fills their details', function () {
    $check = importFile(dataWorkbook([
        'Employees' => [EMPLOYEE_HEADERS, employeeRow()],
        'Income Tax Return' => [['Employee ID', 'Year', 'Date Submitted', 'Date Received'], ['EMP-1', 2026, '2026-03-01', '2026-03-02']],
        'IPCR' => [['Employee ID', 'Year', 'Rating Period', 'Rating'], ['EMP-1', 2026, 'Jan-Jun', 4.5678]],
    ]))->assertSee('All rows passed');

    $this->post('/data/import/commit', ['token' => $check->viewData('token')]);

    expect(ItrSubmission::query()->sole()->last_name)->toBe('Reyes')
        ->and((float) IpcrRating::query()->sole()->rating)->toBe(4.57);
});

it('applies the same date rules as the forms', function () {
    Employee::factory()->create(['employee_id' => 'EMP-1']);

    importFile(dataWorkbook(['Income Tax Return' => [['Employee ID', 'Year', 'Date Submitted', 'Date Received'], ['EMP-1', 2026, '2026-03-02', '2026-03-01']]]))
        ->assertSee('Date received cannot be before the date submitted');
});

it('checks the file again on confirm because the data may have changed', function () {
    $check = importFile(dataWorkbook(['Wellness Leave' => [['Employee ID', 'Date Filing', 'Inclusive Date From', 'Inclusive Date To', 'Status'], ['EMP-1', '2026-03-01', '2026-03-02', '2026-03-02', 'Approved']]]));

    expect($check->viewData('plan')['errors'])->not->toBeEmpty();

    Employee::factory()->create(['employee_id' => 'EMP-1']);
    $fixed = importFile(dataWorkbook(['Wellness Leave' => [['Employee ID', 'Date Filing', 'Inclusive Date From', 'Inclusive Date To', 'Status'], ['EMP-1', '2026-03-01', '2026-03-02', '2026-03-02', 'Approved']]]));
    Employee::query()->delete();

    $this->post('/data/import/commit', ['token' => $fixed->viewData('token')])->assertOk()->assertSee('The data changed since the preview');

    expect(WellnessLeave::query()->count())->toBe(0);
});

it('rolls everything back when a row fails during the commit', function () {
    $plan = app(DataImporter::class)->analyze(dataWorkbook([
        'Branches' => [['Branch Name', 'Code'], ['First', 'SAME'], ['Second', 'SAME']],
    ])->getRealPath(), null);

    $result = app(DataImporter::class)->commit($plan, $this->hr, 'x.xlsx', 'archive.xlsx');

    expect($result['issue']['row'])->toBe(3)
        ->and($result['issue']['message'])->toBe('A record with the same unique value already exists.')
        ->and(Branch::query()->count())->toBe(0)
        ->and(ImportJob::query()->sole()->status)->toBe(ImportJobStatus::Failed);
});

it('refuses uploads that are not workbooks', function () {
    $this->post('/data/import')->assertSessionHas('flash.type', 'error');
    $this->post('/data/import', ['file' => UploadedFile::fake()->create('a.xls', 5)])->assertSessionHas('flash.type', 'error');
    $this->post('/data/import', ['file' => UploadedFile::fake()->createWithContent('a.xlsx', str_repeat('text', 20))])->assertOk()->assertSee('not a valid Excel workbook');
});

it('exports what it imports, with computed columns ignored', function () {
    $branch = Branch::factory()->create(['name' => 'Main', 'address' => '1 Main Street']);
    Employee::factory()->create(['employee_id' => '=CMD()', 'station_id' => $branch->id, 'date_hired' => '2020-01-15']);
    Holiday::factory()->create(['date' => '2026-12-25']);

    $response = $this->get('/data/export?module=all')->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    $book = IOFactory::load($path);
    $sheet = $book->getSheetByName('Employees');
    $headers = $sheet->rangeToArray('A1:AZ1')[0];
    $idColumn = array_search('Employee ID', $headers, true) + 1;

    expect($book->getSheetNames())->toContain('README', 'Branches', 'Employees', 'IPCR')
        ->and($sheet->getCell([$idColumn, 2])->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell([$idColumn, 2])->getValue())->toBe('=CMD()');

    $check = importFile(new UploadedFile($path, 'export.xlsx', test: true))->assertOk();

    expect($check->viewData('plan')['errors'])->toBe([]);
    expect(collect($check->viewData('plan')['plans'])->pluck('action')->unique()->all())->toBe(['unchanged']);
});

it('filters a module export like its page', function () {
    Employee::factory()->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);
    Employee::factory()->create(['first_name' => 'Ben', 'last_name' => 'Cruz']);

    $response = $this->get('/data/export?module=employees&q=Reyes')->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    expect($response->headers->get('Content-Disposition'))->toContain('hr-employees-filtered-')
        ->and(IOFactory::load($path)->getSheetByName('Employees')->getHighestDataRow())->toBe(2);

    $this->get('/data/export?module=nothing')->assertStatus(400);
    $this->get('/data/export?module=itr-submissions&year=2026')->assertOk();
    $this->get('/data/export?module=wellness-leaves&year=2026')->assertOk();
    $this->get('/data/export?module=applications&status[]=Hired')->assertOk();
});

it('gives templates with headers and dropdowns', function () {
    $response = $this->get('/data/template?module=holidays')->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    $sheet = IOFactory::load($path)->getSheetByName('Holidays');

    expect($sheet->getCell('A1')->getValue())->toBe('Date')
        ->and($sheet->getDataValidation('C2')->getType())->toBe('list');

    $this->get('/data/template')->assertOk();
    $this->get('/data')->assertOk()->assertSee('Export ALL data');
});

it('imports and exports bank details, keeping leading zeros', function () {
    $check = importFile(dataWorkbook(['Employees' => [[...EMPLOYEE_HEADERS, 'Bank Name', 'Bank Account Number'], [...employeeRow(), 'Sample Bank', '0012345678']]]))->assertOk();
    $this->post('/data/import/commit', ['token' => $check->viewData('token')]);

    expect(Employee::query()->sole())->bank_name->toBe('Sample Bank')->bank_account_number->toBe('0012345678');

    $bad = importFile(dataWorkbook(['Employees' => [[...EMPLOYEE_HEADERS, 'Bank Account Number'], [...employeeRow(['EMP-2']), 'not-a-number']]]));
    $bad->assertOk()->assertSee('digits, spaces and hyphens only');

    $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
    file_put_contents($path, $this->get('/data/export?module=employees')->assertOk()->streamedContent());
    $sheet = IOFactory::load($path)->getSheetByName('Employees');
    $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1')[0];
    $column = array_search('Bank Account Number', $headers, true) + 1;

    expect($sheet->getCell([$column, 2])->getValue())->toBe('0012345678');
});

it('sets an employee\'s unit, and so their branch, from the Station / Division / Unit column', function () {
    $branch = Branch::factory()->create(['name' => 'Mintal Post Office']);
    BranchUnit::factory()->create(['branch_id' => $branch->id, 'name' => 'Accounting Section']);

    $check = importFile(dataWorkbook(['Employees' => [[...EMPLOYEE_HEADERS, 'Station / Division / Unit'], [...employeeRow(), 'accounting section']]]))->assertOk()->assertSee('All rows passed every check');
    $this->post('/data/import/commit', ['token' => $check->viewData('token')])->assertRedirect('/data');

    $employee = Employee::query()->sole();

    expect($employee->unit->name)->toBe('Accounting Section')->and($employee->station_id)->toBe($branch->id);
});

it('refuses a unit that does not exist or is ambiguous', function () {
    BranchUnit::factory()->create(['name' => 'Twin Section']);
    BranchUnit::factory()->create(['name' => 'Twin Section']);

    foreach (['Nowhere Unit' => 'does not exist', 'Twin Section' => 'more than one branch has a unit'] as $unit => $message) {
        importFile(dataWorkbook(['Employees' => [[...EMPLOYEE_HEADERS, 'Station / Division / Unit'], [...employeeRow(), $unit]]]))->assertOk()->assertSee($message, false);
    }

    expect(Employee::query()->count())->toBe(0);
});
