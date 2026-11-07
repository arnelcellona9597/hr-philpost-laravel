<?php

use App\Enums\EmploymentStatus;
use App\Exceptions\UserFacingException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\User;
use App\Services\DashboardStats;
use App\Services\DataImporter;
use App\Services\DataWorkbook;
use App\Services\EmploymentHistoryService;
use App\Support\CertificateBlocks;
use App\Support\HrDate;
use App\Support\ImportModules;
use App\Support\ReportingPeriod;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function history(): EmploymentHistoryService
{
    return app(EmploymentHistoryService::class);
}

/**
 * An employee who left and was hired again: 2018 to 2020, then 2022 on.
 */
function rehiredEmployee(): Employee
{
    $employee = Employee::factory()->create(['date_hired' => '2018-03-05', 'employment_status' => 'Active', 'last_day_of_service' => null]);
    $employee->update(['employment_status' => 'Resigned', 'last_day_of_service' => '2020-06-30']);
    history()->rehire($employee, '2022-01-10', null);

    return $employee->fresh();
}

beforeEach(fn () => signIn(User::factory()->create()));

it('starts the history with one period when an employee is hired', function () {
    $employee = Employee::factory()->create(['date_hired' => '2020-01-15', 'position' => 'Clerk']);

    $period = EmploymentPeriod::query()->where('employee_id', $employee->id)->sole();

    expect($period->hired_on->toDateString())->toBe('2020-01-15')
        ->and($period->separated_on)->toBeNull()
        ->and($period->separation_type)->toBeNull()
        ->and($period->position)->toBe('Clerk')
        ->and($period->isOpen())->toBeTrue();
});

it('keeps the latest period in step when the employee record changes', function () {
    $employee = Employee::factory()->create(['date_hired' => '2020-01-15']);

    $employee->update(['employment_status' => 'Resigned', 'last_day_of_service' => '2023-02-28', 'position' => 'Supervisor']);
    $period = $employee->employmentPeriods()->sole();

    expect($period->separated_on->toDateString())->toBe('2023-02-28')
        ->and($period->separation_type)->toBe(EmploymentStatus::Resigned)
        ->and($period->position)->toBe('Supervisor');

    $employee->update(['employment_status' => 'Active', 'last_day_of_service' => null]);

    expect($employee->employmentPeriods()->sole()->isOpen())->toBeTrue();
});

it('adds a period when someone is hired again and keeps the old one', function () {
    $employee = rehiredEmployee();
    $periods = $employee->employmentPeriods()->get();

    expect($periods)->toHaveCount(2)
        ->and($periods[0]->hired_on->toDateString())->toBe('2018-03-05')
        ->and($periods[0]->separated_on->toDateString())->toBe('2020-06-30')
        ->and($periods[0]->separation_type)->toBe(EmploymentStatus::Resigned)
        ->and($periods[1]->hired_on->toDateString())->toBe('2022-01-10')
        ->and($periods[1]->isOpen())->toBeTrue()
        ->and($employee->date_hired->toDateString())->toBe('2022-01-10')
        ->and($employee->employment_status)->toBe(EmploymentStatus::Active)
        ->and($employee->last_day_of_service)->toBeNull();
});

it('refuses to hire someone who is still employed', function () {
    $employee = Employee::factory()->create(['date_hired' => '2020-01-15']);

    expect(fn () => history()->rehire($employee, '2024-01-01', null))->toThrow(UserFacingException::class, 'still employed');
    expect($employee->employmentPeriods()->count())->toBe(1);
});

it('refuses a new period that overlaps an existing one', function (string $hired, ?string $separated) {
    $employee = rehiredEmployee();

    expect(history()->problem($employee, null, $hired, $separated))->toContain('overlap');
})->with([
    'inside the first' => ['2019-01-01', '2019-12-31'],
    'across the gap start' => ['2020-06-01', '2020-08-01'],
    'over the open one' => ['2022-05-01', null],
    'swallowing both' => ['2017-01-01', null],
]);

it('refuses to move a period onto another one', function () {
    $employee = rehiredEmployee();
    [$first, $second] = $employee->employmentPeriods()->get()->all();

    expect(fn () => history()->update($second, ['hired_on' => '2020-01-01']))->toThrow(UserFacingException::class, 'overlap')
        ->and(fn () => history()->update($first, ['hired_on' => '2018-03-05', 'separated_on' => '2023-01-01', 'separation_type' => 'Resigned']))->toThrow(UserFacingException::class, 'overlap')
        ->and($second->fresh()->hired_on->toDateString())->toBe('2022-01-10');

    history()->update($first, ['hired_on' => '2018-03-05', 'separated_on' => '2021-12-31', 'separation_type' => 'Resigned']);

    expect($first->fresh()->separated_on->toDateString())->toBe('2021-12-31');
});

it('allows a period in the gap and refuses an open one before a later period', function () {
    $employee = rehiredEmployee();

    expect(history()->problem($employee, null, '2020-08-01', '2021-06-30'))->toBeNull()
        ->and(history()->problem($employee, null, '2020-08-01', null))->toContain('overlap');
});

it('refuses a separation before the hire and a rehire before the previous end', function () {
    $employee = Employee::factory()->create(['date_hired' => '2020-01-15']);

    expect(fn () => history()->separate($employee, '2019-12-31', EmploymentStatus::Resigned))->toThrow(UserFacingException::class, 'before the date hired')
        ->and(fn () => history()->separate($employee, '2022-01-01', EmploymentStatus::Active))->toThrow(UserFacingException::class, 'Choose how');

    history()->separate($employee, '2022-01-01', EmploymentStatus::Retired, 'Reached retirement age');

    expect($employee->fresh()->employment_status)->toBe(EmploymentStatus::Retired)
        ->and($employee->fresh()->last_day_of_service->toDateString())->toBe('2022-01-01')
        ->and(fn () => history()->rehire($employee, '2021-12-31', null))->toThrow(UserFacingException::class, 'overlap');
});

it('asks for the previous last day when a separated employee has none', function () {
    $employee = Employee::factory()->create(['date_hired' => '2018-03-05']);
    $employee->update(['employment_status' => 'Terminated', 'last_day_of_service' => null]);

    expect(fn () => history()->rehire($employee, '2022-01-10', null))->toThrow(UserFacingException::class, 'last day of the previous period');

    history()->rehire($employee, '2022-01-10', '2019-04-30');

    expect($employee->employmentPeriods()->first()->separated_on->toDateString())->toBe('2019-04-30');
});

it('puts the earlier period back on the employee when the latest is deleted', function () {
    $employee = rehiredEmployee();

    history()->delete($employee->employmentPeriods()->get()->last());
    $employee->refresh();

    expect($employee->employmentPeriods()->count())->toBe(1)
        ->and($employee->date_hired->toDateString())->toBe('2018-03-05')
        ->and($employee->last_day_of_service->toDateString())->toBe('2020-06-30')
        ->and($employee->employment_status)->toBe(EmploymentStatus::Resigned)
        ->and(fn () => history()->delete($employee->employmentPeriods()->sole()))->toThrow(UserFacingException::class, 'only employment period');
});

it('shows the history in order with gaps and total service', function () {
    $this->travelTo('2026-01-10');
    $employee = rehiredEmployee();

    $summary = history()->summary($employee);

    expect($summary['rows'])->toHaveCount(2)
        ->and($summary['rows'][0]['gap'])->toBeNull()
        ->and($summary['rows'][1]['gap'])->toMatchArray(['years' => 1, 'months' => 6])
        ->and($summary['rows'][0]['length'])->toMatchArray(['years' => 2, 'months' => 3])
        ->and($summary['total'])->toMatchArray(['years' => 6, 'months' => 3]);

    $this->get("/employees/{$employee->id}")->assertOk()->assertSee('Employment history')->assertSee('2 periods')->assertSee('Away for')->assertSee('Record a separation');
});

it('hires, separates, corrects and deletes through the profile', function () {
    $employee = Employee::factory()->create(['date_hired' => '2020-01-15']);
    $branch = Branch::factory()->create();

    $this->post("/employees/{$employee->id}/employment/separate", ['separated_on' => '2022-01-01', 'separation_type' => 'Resigned'])->assertRedirect();
    expect($employee->fresh()->employment_status)->toBe(EmploymentStatus::Resigned);

    $this->get("/employees/{$employee->id}")->assertOk()->assertSee('Hire again');

    $this->post("/employees/{$employee->id}/employment", ['hired_on' => '2023-05-01', 'position' => 'Driver', 'station_id' => $branch->id, 'classification' => 'COS'])->assertRedirect();
    $employee->refresh();
    $second = $employee->employmentPeriods()->get()->last();

    expect($employee->employmentPeriods()->count())->toBe(2)
        ->and($employee->position)->toBe('Driver')
        ->and($employee->station_id)->toBe($branch->id)
        ->and($employee->classification->value)->toBe('COS');

    $this->put("/employees/{$employee->id}/employment/{$second->id}", ['hired_on' => '2023-06-01', 'separation_type' => '', 'position' => 'Driver'])->assertSessionHas('flash.type', 'success');
    expect($employee->fresh()->date_hired->toDateString())->toBe('2023-06-01');

    $this->put("/employees/{$employee->id}/employment/{$second->id}", ['hired_on' => '2021-06-01', 'separation_type' => ''])->assertSessionHas('flash.type', 'error');
    $this->post("/employees/{$employee->id}/employment/separate", ['separated_on' => 'x', 'separation_type' => 'Resigned'])->assertSessionHas('flash.type', 'error');

    $this->delete("/employees/{$employee->id}/employment/{$second->id}")->assertRedirect();
    expect($employee->fresh()->employmentPeriods()->count())->toBe(1);
});

it('keeps another employee\'s periods out of reach', function () {
    $employee = Employee::factory()->create(['date_hired' => '2020-01-15']);
    $other = Employee::factory()->create(['date_hired' => '2019-01-15']);
    $period = $other->employmentPeriods()->sole();

    $this->put("/employees/{$employee->id}/employment/{$period->id}", ['hired_on' => '2019-02-01'])->assertNotFound();
    $this->delete("/employees/{$employee->id}/employment/{$period->id}")->assertNotFound();
});

it('refuses an employee form that would overlap an earlier period', function () {
    $employee = rehiredEmployee();

    $this->put("/employees/{$employee->id}", ['date_hired' => '2020-01-01'])->assertSessionHasErrors(['date_hired']);

    expect($employee->fresh()->date_hired->toDateString())->toBe('2022-01-10');

    $this->put("/employees/{$employee->id}", ['date_hired' => '2022-03-01'])->assertSessionHasNoErrors();
    expect($employee->fresh()->employmentPeriods()->get()->last()->hired_on->toDateString())->toBe('2022-03-01');
});

it('records period changes in the audit log by name', function () {
    $employee = Employee::factory()->create(['date_hired' => '2020-01-15']);
    AuditLog::query()->delete();

    history()->separate($employee, '2022-01-01', EmploymentStatus::Resigned);

    $log = AuditLog::query()->where('collection_slug', 'employment-periods')->sole();

    expect($log->action)->toBe('update')
        ->and($log->doc_label)->toContain($employee->full_name)->toContain('Jan 1, 2022')
        ->and($log->changes)->toHaveKey('separated_on');
});

it('counts a hire again as a hire on the dashboard', function () {
    $this->travelTo('2026-11-15');
    $employee = Employee::factory()->create(['date_hired' => '2024-03-01']);
    $employee->update(['employment_status' => 'Resigned', 'last_day_of_service' => '2025-06-30']);
    history()->rehire($employee, '2026-03-01', null);

    $movement = app(DashboardStats::class)->period(ReportingPeriod::resolve('this-year'), '2025-12-01', null, null);

    expect(array_sum($movement['hiresByDay']))->toBe(1)
        ->and($movement['prevHires'])->toBe(0)
        ->and($movement['prevSeparations'])->toBe(1)
        ->and(array_sum($movement['separationsByDay']))->toBe(0);
});

it('offers the first hire and total service to certificates', function () {
    $this->travelTo('2026-01-10');
    $employee = rehiredEmployee();

    $values = CertificateBlocks::values($employee, '2026-01-10', null, 'COE-2026-0001', 'PhilPost');

    expect($values['dateHired'])->toBe('January 10, 2022')
        ->and($values['firstDateHired'])->toBe('March 5, 2018')
        ->and($values['totalLengthOfService'])->toBe('6 years and 3 months')
        ->and($values['employmentCount'])->toBe('2')
        ->and(CertificateBlocks::values(Employee::factory()->create(['date_hired' => '2020-01-10']), '2026-01-10', null, null, '')['employmentCount'])->toBe('1');
});

it('exports and imports the employment history', function () {
    $employee = rehiredEmployee();
    $module = ImportModules::find('employment-history');

    expect(array_column($module['fields'], 'label'))->toBe(['Employee ID', 'Date Hired', 'Date Separated', 'Separation Type', 'Position / Designation', 'Station / Division / Unit', 'Classification', 'Remarks']);

    $spreadsheet = app(DataWorkbook::class)->build([['module' => $module, 'query' => app(DataWorkbook::class)->query($module)]]);
    $path = tempnam(sys_get_temp_dir(), 'history').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $plan = app(DataImporter::class)->analyze($path, null);

    expect($plan['errors'])->toBe([])
        ->and(collect($plan['plans'])->pluck('action')->unique()->all())->toBe(['unchanged'])
        ->and($plan['plans'])->toHaveCount(2);
});

it('imports a period and refuses an overlapping one', function () {
    $employee = Employee::factory()->create(['employee_id' => 'EMP-9', 'date_hired' => '2022-01-10']);
    $importer = app(DataImporter::class);

    $file = fn (array $rows): string => (function () use ($rows): string {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Employment History')->fromArray([['Employee ID', 'Date Hired', 'Date Separated', 'Separation Type'], ...$rows], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'history').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    })();

    $ok = $importer->validate($file([['EMP-9', '2018-03-05', '2020-06-30', 'Resigned']]), null, auth()->user());
    expect($ok['errors'])->toBe([])->and($ok['plans'][0]['action'])->toBe('create');

    $importer->commit($ok, auth()->user(), 'history.xlsx', 'archive.xlsx');
    expect($employee->fresh()->employmentPeriods()->count())->toBe(2)->and($employee->fresh()->date_hired->toDateString())->toBe('2022-01-10');

    $overlap = $importer->validate($file([['EMP-9', '2019-01-01', '2019-12-31', 'Resigned']]), null, auth()->user());
    expect($overlap['errors'][0]['message'])->toContain('overlap');

    $noType = $importer->validate($file([['EMP-9', '2010-01-01', '2011-01-01', '']]), null, auth()->user());
    expect($noType['errors'][0]['message'])->toContain('Choose how the employment ended');
});

it('removes the history with the employee', function () {
    $employee = rehiredEmployee();
    $id = $employee->id;

    $employee->employmentPeriods()->delete();
    Employee::query()->whereKey($id)->delete();

    expect(EmploymentPeriod::query()->where('employee_id', $id)->count())->toBe(0);
});

it('backfills one period per hired employee when the table is created', function () {
    $migration = require glob(database_path('migrations/*create_employment_periods_table.php'))[0];
    $active = Employee::factory()->create(['date_hired' => '2020-01-15']);
    $gone = Employee::factory()->create(['date_hired' => '2019-02-01']);
    $gone->update(['employment_status' => 'Retired', 'last_day_of_service' => '2024-03-31']);
    Employee::factory()->create(['date_hired' => null]);

    Schema::dropIfExists('employment_periods');
    $migration->up();

    $rows = EmploymentPeriod::query()->orderBy('employee_id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[1]->separation_type)->toBe(EmploymentStatus::Retired)
        ->and($rows[1]->separated_on->toDateString())->toBe('2024-03-31')
        ->and($rows[0]->isOpen())->toBeTrue()
        ->and(HrDate::today())->not->toBeEmpty();
});

it('shows the same total service on the profile badge, the list and the history', function () {
    $this->travelTo('2026-11-07');
    $employee = rehiredEmployee();
    $total = HrDate::formatSpan(history()->summary($employee->fresh())['total']);

    expect($employee->fresh()->length_of_service)->toBe($total)
        ->and($total)->not->toBe(HrDate::formatSpan(HrDate::lengthOfService('2022-01-10')));

    $this->get("/employees/{$employee->id}")->assertOk()->assertSee("{$total} in service")->assertSee("{$total} in total");
    $this->get('/employees')->assertOk()->assertSee($total);
    $this->get("/employees/{$employee->id}/print")->assertOk()->assertSee("Total service: {$total}");
});

it('counts a single-period employee the same as before', function () {
    $this->travelTo('2026-06-30T16:30:00Z');
    $employee = Employee::factory()->create(['date_hired' => '2024-07-01', 'last_day_of_service' => null]);

    expect($employee->fresh()->length_of_service)->toBe('2 yrs');
});
