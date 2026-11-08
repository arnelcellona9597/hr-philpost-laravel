<?php

use App\Enums\EmploymentStatus;
use App\Models\Branch;
use App\Models\BranchUnit;
use App\Models\Employee;
use App\Services\CosMasterImport;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const MASTER_HEADERS = [null, 'STATUS', 'LBP ACCOUNT', 'Last Name', 'First Name', 'Middle Name', 'Extn.', null, 'Gender', 'Date of Birth', 'Age', 'Civil Status', 'Religion', 'Blood Type', 'Professional Eligibility / License', 'Contact Number', null, 'Address', 'Email Address', 'Educational Attainment', 'Courses', '  Year Level/ Earned Units', 'Academic Honors Received  ', 'Employee ID', 'Division/Unit', 'Branches', 'Region', 'Position/Designation', 'Nature of Work', 'Driver License  Number  ', 'Date Hired', 'End1', 'Rehire', 'End2', 'Rehire2', 'End3', 'Rehire3', 'Length in Service', 'SSS Number ', 'Pag-IBIG Number ', 'TIN Number ', 'PhilHealth Number  ', 'Emergency Contact Person ', 'Relationship', 'Contact Number ', 'Address', 'REMARK'];

/**
 * A "COS Master File" workbook with fictional people. Each row is keyed by header text.
 *
 * @param  list<array<string, mixed>>  $people
 */
function masterWorkbook(array $people): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet()->setTitle('COS Master File');
    $sheet->fromArray(MASTER_HEADERS, null, 'A1');

    foreach ($people as $index => $person) {
        $cells = array_fill(0, count(MASTER_HEADERS), null);

        foreach ($person as $header => $value) {
            $position = array_search($header, MASTER_HEADERS, true);
            $cells[$position === false ? array_search(trim($header), array_map(fn (?string $name): ?string => $name === null ? null : trim($name), MASTER_HEADERS), true) : $position] = $value;
        }

        $sheet->fromArray($cells, null, 'A'.($index + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'cos').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function person(array $overrides = []): array
{
    return [...['Last Name' => 'REYES', 'First Name' => 'MARIA', 'Middle Name' => 'LOPEZ', 'Gender' => 'F', 'Date of Birth' => '1990-04-12', 'Employee ID' => '20-001', 'Date Hired' => '2020-02-03'], ...$overrides];
}

function importPlan(array $people): array
{
    $import = new CosMasterImport;

    return [$import, $import->plan($import->read(masterWorkbook($people)))];
}

it('changes nothing until applied', function () {
    Employee::factory()->create(['employee_id' => '20-001', 'last_name' => 'Reyes', 'first_name' => 'Maria', 'date_of_birth' => '1990-04-12', 'email' => 'old@example.test']);
    $before = Employee::query()->count();

    [, $plan] = importPlan([person(['Email Address' => 'new@example.test']), person(['Last Name' => 'SANTOS', 'First Name' => 'ANA', 'Employee ID' => '20-002'])]);

    expect($plan['counts'])->toBe(['update' => 1, 'new' => 1, 'skip' => 0])
        ->and(Employee::query()->count())->toBe($before)
        ->and(Employee::query()->where('employee_id', '20-001')->value('email'))->toBe('old@example.test')
        ->and(Branch::query()->count())->toBe(0);
});

it('updates matches by Employee ID, adds new people and never deletes', function () {
    $maria = Employee::factory()->create(['employee_id' => '20-001', 'last_name' => 'Reyes', 'first_name' => 'Maria', 'date_of_birth' => '1990-04-12', 'email' => 'old@example.test', 'religion' => 'Islam', 'position' => 'Clerk']);
    $left = Employee::factory()->create(['employee_id' => '19-999', 'last_name' => 'Notinfile']);

    [$import, $plan] = importPlan([
        person(['Email Address' => 'NEW@Example.test', 'Religion' => null, 'Position/Designation' => 'Acting Letter Carrier', 'Region' => 'CARAGA', 'Civil Status' => 'Married', 'Educational Attainment' => 'High School Graduate, College Graduate']),
        person(['Last Name' => 'SANTOS', 'First Name' => 'ANA', 'Employee ID' => '20-002', 'Gender' => 'Female', 'Date of Birth' => '1995-01-01']),
    ]);
    $result = $import->apply($plan);

    expect($result)->toMatchArray(['created' => 1, 'updated' => 1]);

    $maria = $maria->fresh();
    expect($maria->email)->toBe('new@example.test')
        ->and($maria->religion)->toBe('Islam')
        ->and($maria->position)->toBe('Acting Letter Carrier')
        ->and($maria->region->value)->toBe('Region XIII')
        ->and($maria->civil_status->value)->toBe('Married')
        ->and($maria->educational_attainment->value)->toBe('College Graduate')
        ->and($plan['missing'])->toBe(['19-999'])
        ->and($left->fresh())->not->toBeNull()
        ->and(Employee::query()->where('employee_id', '20-002')->firstOrFail()->classification->value)->toBe('COS');
});

it('matches people with no Employee ID by name and birth date and gives new ones a temporary ID', function () {
    $ana = Employee::factory()->create(['employee_id' => '18-050', 'last_name' => 'Santos', 'first_name' => 'Ana', 'date_of_birth' => '1995-01-01']);

    [$import, $plan] = importPlan([
        person(['Last Name' => 'SANTOS', 'First Name' => 'ANA', 'Employee ID' => null, 'Date of Birth' => '1995-01-01', 'Contact Number' => '09171234567']),
        person(['Last Name' => 'CRUZ', 'First Name' => 'JUAN', 'Employee ID' => '00-000', 'Gender' => 'M', 'Date of Birth' => '1988-08-08']),
    ]);

    expect(collect($plan['items'])->pluck('how')->all())->toBe(['name and birth date', '']);

    $import->apply($plan);

    expect($ana->fresh()->contact_number)->toBe('09171234567')
        ->and(Employee::query()->where('last_name', 'CRUZ')->value('employee_id'))->toBe('TEMP-0001')
        ->and(Employee::query()->count())->toBe(2);
});

it('skips ambiguous people and new people without a gender, but still adds someone whose ID belongs to another person', function () {
    Employee::factory()->create(['employee_id' => '17-001', 'last_name' => 'Twin', 'first_name' => 'One', 'date_of_birth' => '1990-01-01']);
    Employee::factory()->create(['employee_id' => '17-002', 'last_name' => 'Twin', 'first_name' => 'One', 'date_of_birth' => '1990-01-01']);
    Employee::factory()->create(['employee_id' => '17-003', 'last_name' => 'Owner', 'first_name' => 'Real', 'date_of_birth' => '1980-05-05']);

    [, $plan] = importPlan([
        person(['Last Name' => 'TWIN', 'First Name' => 'ONE', 'Employee ID' => null, 'Date of Birth' => '1990-01-01']),
        person(['Last Name' => 'OTHER', 'First Name' => 'PERSON', 'Employee ID' => '17-003', 'Date of Birth' => '1999-09-09']),
        person(['Last Name' => 'NOGENDER', 'First Name' => 'PAT', 'Employee ID' => '21-777', 'Gender' => null]),
    ]);

    expect($plan['counts'])->toBe(['update' => 0, 'new' => 1, 'skip' => 2])
        ->and(collect($plan['items'])->pluck('problem')->implode(' | '))->toContain('More than one employee')->toContain('no usable gender')
        ->and($plan['items'][1]['generated_id'])->toBe('TEMP-0001')
        ->and($plan['items'][1]['row']['warnings'][0])->toContain('already belongs to another person');
});

it('builds employment history from the end and rehire dates and reads the status from the position', function () {
    [$import, $plan] = importPlan([person([
        'Employee ID' => '20-010', 'Position/Designation' => 'Resigned-A/Letter Carrier', 'Date Hired' => '2015-01-05', 'End1' => '2018-06-30', 'Rehire' => '2020-02-03', 'End2' => '2024-03-31',
    ])]);
    $import->apply($plan);

    $employee = Employee::query()->where('employee_id', '20-010')->firstOrFail();
    $periods = $employee->employmentPeriods;

    expect($periods)->toHaveCount(2)
        ->and($periods[0]->hired_on->toDateString())->toBe('2015-01-05')
        ->and($periods[0]->separated_on->toDateString())->toBe('2018-06-30')
        ->and($periods[1]->hired_on->toDateString())->toBe('2020-02-03')
        ->and($periods[1]->separated_on->toDateString())->toBe('2024-03-31')
        ->and($employee->employment_status)->toBe(EmploymentStatus::Resigned)
        ->and($employee->position)->toBe('Acting Letter Carrier')
        ->and($employee->date_hired->toDateString())->toBe('2020-02-03')
        ->and($employee->last_day_of_service->toDateString())->toBe('2024-03-31');
});

it('replaces the old periods with the file\'s and keeps a person with no end date active', function () {
    $employee = Employee::factory()->create(['employee_id' => '20-011', 'last_name' => 'Reyes', 'first_name' => 'Maria', 'date_of_birth' => '1990-04-12', 'date_hired' => '2001-01-01']);

    [$import, $plan] = importPlan([person(['Employee ID' => '20-011', 'Date Hired' => '2022-05-02'])]);
    $import->apply($plan);

    $periods = $employee->fresh()->employmentPeriods;

    expect($periods)->toHaveCount(1)
        ->and($periods[0]->hired_on->toDateString())->toBe('2022-05-02')
        ->and($periods[0]->separated_on)->toBeNull()
        ->and($employee->fresh()->employment_status)->toBe(EmploymentStatus::Active);
});

it('leaves unreadable values unchanged and says why', function () {
    $maria = Employee::factory()->create(['employee_id' => '20-001', 'last_name' => 'Reyes', 'first_name' => 'Maria', 'date_of_birth' => '1990-04-12', 'sss' => '12-3456789-0']);

    [$import, $plan] = importPlan([person(['Civil Status' => 'Live-in', 'Blood Type' => 'Unknown', 'SSS Number ' => '123', 'Email Address' => 'not-an-email', 'Date of Birth' => '1990-04-12'])]);
    $import->apply($plan);

    $warnings = implode(' | ', $plan['items'][0]['row']['warnings']);

    expect($warnings)->toContain('Civil status')->toContain('Blood type')->toContain('digits')->toContain('Email')
        ->and($maria->fresh()->sss)->toBe('12-3456789-0');
});

it('shows a dry run by default and saves only with --commit', function () {
    Employee::factory()->create(['employee_id' => '20-001', 'last_name' => 'Reyes', 'first_name' => 'Maria', 'date_of_birth' => '1990-04-12', 'email' => 'old@example.test']);
    $file = masterWorkbook([person(['Email Address' => 'new@example.test'])]);

    $this->artisan('hr:import-cos-master', ['file' => $file])->expectsOutputToContain('Nothing was changed')->assertSuccessful();
    expect(Employee::query()->where('employee_id', '20-001')->value('email'))->toBe('old@example.test');

    $this->artisan('hr:import-cos-master', ['file' => $file, '--commit' => true, '--force' => true])->expectsOutputToContain('1 employees updated')->assertSuccessful();
    expect(Employee::query()->where('employee_id', '20-001')->value('email'))->toBe('new@example.test');
});

it('refuses a workbook without the master sheet', function () {
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->setTitle('Other');
    $path = tempnam(sys_get_temp_dir(), 'cos').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $this->artisan('hr:import-cos-master', ['file' => $path])->assertFailed();
});

it('sets the branch from the Branches column and puts the Division/Unit under it', function () {
    $branch = Branch::factory()->create(['name' => 'Cotabato City Post Office']);
    $employee = Employee::factory()->create(['employee_id' => '20-001', 'last_name' => 'Reyes', 'first_name' => 'Maria', 'date_of_birth' => '1990-04-12', 'station_id' => null]);

    [$import, $plan] = importPlan([person(['Branches' => 'Cotabato City Post Office, Maguindanao', 'Division/Unit' => 'Accounting Section, AdFin Division'])]);
    $result = $import->apply($plan);

    $employee = $employee->fresh();

    expect($result)->toMatchArray(['units' => 1, 'branches' => 0])
        ->and($employee->station_id)->toBe($branch->id)
        ->and($employee->unit->name)->toBe('Accounting Section, AdFin Division')
        ->and($employee->unit->branch_id)->toBe($branch->id)
        ->and($employee->employmentPeriods()->first()->station_id)->toBe($branch->id)
        ->and($plan['branches'])->toBe(['existing' => 1, 'new' => []])
        ->and($plan['units'])->toBe(['existing' => 0, 'new' => 1]);
});

it('joins the spellings of one branch, creates the ones that are missing, and reuses existing units', function () {
    $mintal = Branch::factory()->create(['name' => 'Mintal Post Office']);
    $unit = BranchUnit::factory()->create(['branch_id' => $mintal->id, 'name' => 'Records Unit']);

    [$import, $plan] = importPlan([
        person(['Employee ID' => '20-020', 'Last Name' => 'A', 'Branches' => 'Tandag City Post Office, Surigao del Sur']),
        person(['Employee ID' => '20-021', 'Last Name' => 'B', 'Branches' => 'Tandag City P.O']),
        person(['Employee ID' => '20-022', 'Last Name' => 'C', 'Branches' => 'Tandag P.O']),
        person(['Employee ID' => '20-023', 'Last Name' => 'D', 'Branches' => 'Bunawan']),
        person(['Employee ID' => '20-024', 'Last Name' => 'E', 'Branches' => 'Mintal P.O', 'Division/Unit' => 'records unit']),
    ]);
    $result = $import->apply($plan);

    expect($result)->toMatchArray(['branches' => 2, 'units' => 0])
        ->and(Branch::query()->orderBy('name')->pluck('name')->all())->toBe(['Bunawan Post Office', 'Mintal Post Office', 'Tandag City Post Office'])
        ->and(Employee::query()->where('employee_id', '20-022')->first()->station->name)->toBe('Tandag City Post Office')
        ->and(Employee::query()->where('employee_id', '20-024')->first()->unit_id)->toBe($unit->id)
        ->and($plan['branches']['new'])->toBe(['Tandag City Post Office' => 3, 'Bunawan Post Office' => 1])
        ->and($plan['units'])->toBe(['existing' => 1, 'new' => 0]);
});

it('keeps a unit with no branch when the row has no branch, and places a waiting unit when a branch is given', function () {
    $waiting = BranchUnit::factory()->unplaced()->create(['name' => 'Waiting Unit']);

    [$import, $plan] = importPlan([
        person(['Employee ID' => '20-030', 'Last Name' => 'A', 'Division/Unit' => 'Loose Unit']),
        person(['Employee ID' => '20-031', 'Last Name' => 'B', 'Branches' => 'Tagum City Post Office', 'Division/Unit' => 'Waiting Unit']),
    ]);
    $import->apply($plan);

    $a = Employee::query()->where('employee_id', '20-030')->firstOrFail();
    $b = Employee::query()->where('employee_id', '20-031')->firstOrFail();

    expect($a->unit->name)->toBe('Loose Unit')->and($a->unit->branch_id)->toBeNull()->and($a->station_id)->toBeNull()
        ->and($b->unit_id)->toBe($waiting->id)
        ->and($waiting->fresh()->branch_id)->toBe($b->station_id)
        ->and($b->station->name)->toBe('Tagum City Post Office');
});

it('adds both people when two rows in the file share an Employee ID, the second with a temporary ID', function () {
    [$import, $plan] = importPlan([
        person(['Employee ID' => '00-012', 'Last Name' => 'FIRST', 'First Name' => 'ONE', 'Date of Birth' => '1980-12-28']),
        person(['Employee ID' => '00-012', 'Last Name' => 'SECOND', 'First Name' => 'TWO', 'Date of Birth' => '1993-07-13']),
    ]);
    $import->apply($plan);

    expect(Employee::query()->where('last_name', 'FIRST')->value('employee_id'))->toBe('00-012')
        ->and(Employee::query()->where('last_name', 'SECOND')->value('employee_id'))->toBe('TEMP-0001')
        ->and($plan['items'][1]['row']['warnings'][0])->toContain('used by someone earlier in the file');
});
