<?php

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\PayChangeType;
use App\Enums\RateType;
use App\Enums\Region;
use App\Exceptions\UserFacingException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Contract;
use App\Models\ContractSetting;
use App\Models\Employee;
use App\Models\PayChange;
use App\Models\PositionChange;
use App\Models\User;
use App\Models\WageOrder;
use App\Services\ContractService;
use App\Services\EmploymentHistoryService;
use App\Services\PayHistoryService;
use App\Services\PositionHistoryService;
use Database\Seeders\RegionalWageSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function worker(array $attributes = []): Employee
{
    return Employee::factory()->create([
        'classification' => Classification::Cos,
        'employment_status' => EmploymentStatus::Active,
        'region' => Region::RegionXi,
        'date_hired' => '2024-01-08',
        'position' => 'Mail Sorter',
        ...$attributes,
    ]);
}

function payForm(array $overrides = []): array
{
    return ['effective_on' => '2026-03-01', 'change_type' => 'Salary increase', 'rate_type' => 'Daily', 'amount' => '520.50', ...$overrides];
}

beforeEach(function () {
    Storage::fake('local');
    $this->hr = User::factory()->create();
    signIn($this->hr);
    $this->travelTo('2026-06-15 10:00:00');
});

it('records wage orders per region and shows the rate in force', function () {
    $this->get('/wage-orders/new')->assertOk()->assertSee('Daily Minimum Wage');

    $this->post('/wage-orders', ['region' => 'Region XI', 'order_number' => 'RB XI-23', 'sector' => 'Non-agriculture', 'daily_rate_centavos' => '510.00', 'effective_on' => '2026-01-01'])->assertRedirect();
    WageOrder::factory()->create(['region' => Region::RegionXi, 'order_number' => 'RB XI-24', 'daily_rate_centavos' => 55_000, 'effective_on' => '2026-09-01']);
    WageOrder::factory()->create(['region' => Region::Ncr, 'order_number' => 'NCR-26', 'daily_rate_centavos' => 64_500, 'effective_on' => '2025-07-01']);

    expect(WageOrder::query()->where('order_number', 'RB XI-23')->sole()->daily_rate_centavos)->toBe(51_000);

    $page = $this->get('/wage-orders')->assertOk()->assertSee('₱510.00')->assertSee('₱645.00')->assertSee('No wage order recorded');

    expect($page->viewData('inForce')->get('Region XI')->order_number)->toBe('RB XI-23');
    $this->get('/wage-orders?region=NCR')->assertOk()->assertSee('NCR-26')->assertDontSee('RB XI-24');
});

it('refuses a second wage order for the same region, sector and date, and a bad rate', function () {
    WageOrder::factory()->create(['effective_on' => '2026-01-01']);
    $form = ['region' => 'Region XI', 'order_number' => 'X', 'sector' => 'Non-agriculture', 'daily_rate_centavos' => '510', 'effective_on' => '2026-01-01'];

    $this->from('/wage-orders/new')->post('/wage-orders', $form)->assertSessionHasErrors('effective_on');
    $this->from('/wage-orders/new')->post('/wage-orders', [...$form, 'effective_on' => '2026-02-01', 'daily_rate_centavos' => 'abc'])->assertSessionHasErrors('daily_rate_centavos');

    expect(WageOrder::query()->count())->toBe(1);
});

it('shows who a wage order changes and raises them once', function () {
    $order = WageOrder::factory()->create(['daily_rate_centavos' => 50_000, 'effective_on' => '2026-03-01']);
    $below = worker();
    PayChange::factory()->create(['employee_id' => $below->id, 'amount_centavos' => 45_000]);
    $none = worker();
    $above = worker();
    PayChange::factory()->create(['employee_id' => $above->id, 'amount_centavos' => 60_000]);
    $monthly = worker(['classification' => Classification::Regular]);
    PayChange::factory()->create(['employee_id' => $monthly->id, 'rate_type' => RateType::Monthly, 'amount_centavos' => 2_500_000]);
    $regular = worker(['classification' => Classification::Regular]);
    $otherRegion = worker(['region' => Region::Ncr]);
    $left = worker(['employment_status' => EmploymentStatus::Resigned, 'last_day_of_service' => '2025-12-31']);
    $later = worker(['date_hired' => '2026-05-01']);

    $impact = app(PayHistoryService::class)->impact($order);

    expect(array_column($impact['pending'], 'employee_id'))->toEqualCanonicalizing([$below->id, $none->id, $later->id])
        ->and($impact)->toMatchArray(['applied' => 0, 'atOrAbove' => 1, 'monthly' => 1, 'noRate' => 1]);

    $this->get("/wage-orders/{$order->id}")->assertOk()->assertSee('Apply to 3 employees');
    $this->post("/wage-orders/{$order->id}/apply")->assertRedirect("/wage-orders/{$order->id}");

    $raised = PayChange::query()->where('wage_order_id', $order->id)->pluck('employee_id');

    expect($raised)->toHaveCount(3)
        ->and(app(PayHistoryService::class)->rateOn($below)->amount_centavos)->toBe(50_000)
        ->and(app(PayHistoryService::class)->rateOn($below)->change_type)->toBe(PayChangeType::WageOrder)
        ->and(AuditLog::query()->where('collection_slug', 'wage-orders')->where('action', 'update')->count())->toBe(1);

    expect(app(PayHistoryService::class)->apply($order))->toBe(0);
    $this->get("/wage-orders/{$order->id}")->assertSee('Nobody needs a change');

    $hiredLater = app(PayHistoryService::class)->rateOn($later);

    expect($hiredLater->effective_on->toDateString())->toBe('2026-05-01')
        ->and($hiredLater->change_type)->toBe(PayChangeType::Starting);

    foreach ([$otherRegion, $left, $regular] as $untouched) {
        expect(PayChange::query()->where('employee_id', $untouched->id)->exists())->toBeFalse();
    }
});

it('uses the station region when the employee has none', function () {
    $branch = Branch::factory()->create(['region' => Region::RegionXi]);
    $employee = worker(['region' => null, 'station_id' => $branch->id]);
    $order = WageOrder::factory()->create(['effective_on' => '2026-03-01']);

    expect(app(PayHistoryService::class)->impact($order)['pending'][0]['employee_id'])->toBe($employee->id);
});

it('will not delete a wage order that was applied', function () {
    $order = WageOrder::factory()->create(['effective_on' => '2026-03-01']);
    worker();
    app(PayHistoryService::class)->apply($order);

    $this->delete("/wage-orders/{$order->id}")->assertRedirect("/wage-orders/{$order->id}");
    expect(WageOrder::query()->count())->toBe(1);

    $unused = WageOrder::factory()->create(['effective_on' => '2026-04-01']);
    $this->delete("/wage-orders/{$unused->id}")->assertRedirect('/wage-orders');
    expect(WageOrder::query()->count())->toBe(1);
});

it('records salary increases in order on the profile and printout', function () {
    $employee = worker();
    PayChange::factory()->create(['employee_id' => $employee->id, 'effective_on' => '2024-01-08', 'amount_centavos' => 45_000]);

    $this->post("/employees/{$employee->id}/pay", payForm(['reference' => 'Memo 12']))->assertRedirect("/employees/{$employee->id}#pay-history");

    $summary = app(PayHistoryService::class)->summary($employee);

    expect($summary['rows'])->toHaveCount(2)
        ->and($summary['rows'][1]['difference'])->toBe(7_050)
        ->and($summary['current']->amount_centavos)->toBe(52_050);

    $this->get("/employees/{$employee->id}")->assertOk()->assertSee('₱520.50')->assertSee('+₱70.50')->assertSee('Memo 12');
    $this->get("/employees/{$employee->id}/print")->assertOk()->assertSee('Compensation History')->assertDontSee('Salary History')->assertDontSee('Pay history')->assertSee('₱520.50')->assertSee('Salary increase');
});

it('treats a future rate as upcoming, not current', function () {
    $employee = worker();
    PayChange::factory()->create(['employee_id' => $employee->id, 'amount_centavos' => 45_000]);
    PayChange::factory()->create(['employee_id' => $employee->id, 'effective_on' => '2026-12-01', 'amount_centavos' => 50_000, 'change_type' => PayChangeType::Increase]);

    $summary = app(PayHistoryService::class)->summary($employee);

    expect($summary['current']->amount_centavos)->toBe(45_000)->and($summary['rows'][1]['upcoming'])->toBeTrue();
});

it('warns when a daily rate is below the regional minimum', function () {
    WageOrder::factory()->create(['daily_rate_centavos' => 50_000, 'order_number' => 'RB XI-23']);
    $below = worker();
    PayChange::factory()->create(['employee_id' => $below->id, 'amount_centavos' => 45_000]);
    $monthly = worker();
    PayChange::factory()->create(['employee_id' => $monthly->id, 'rate_type' => RateType::Monthly, 'amount_centavos' => 2_000_000]);

    $this->get("/employees/{$below->id}")->assertSee('below the regional minimum')->assertSee('RB XI-23');
    $this->get("/employees/{$monthly->id}")->assertDontSee('below the regional minimum');
});

it('refuses an incomplete or repeated pay record', function (array $change, string $message) {
    $employee = worker();
    PayChange::factory()->create(['employee_id' => $employee->id, 'effective_on' => '2026-03-01']);

    $this->post("/employees/{$employee->id}/pay", payForm(['effective_on' => '2026-04-01', ...$change]))->assertSessionHas('flash', fn (array $flash): bool => $flash['type'] === 'error' && str_contains($flash['message'], $message));

    expect(PayChange::query()->where('employee_id', $employee->id)->count())->toBe(1);
})->with([
    'same date' => [['effective_on' => '2026-03-01'], 'already a compensation record'],
    'bad date' => [['effective_on' => 'soon'], 'valid effective date'],
    'no rate' => [['amount' => ''], 'Enter the new rate'],
    'bad rate' => [['amount' => 'abc'], 'not an amount'],
    'bad kind' => [['change_type' => 'Bonus'], 'kind of change'],
]);

it('deletes only a pay record of that employee', function () {
    $employee = worker();
    $change = PayChange::factory()->create(['employee_id' => $employee->id]);

    $this->delete('/employees/'.worker()->id."/pay/{$change->id}")->assertNotFound();
    $this->delete("/employees/{$employee->id}/pay/{$change->id}")->assertRedirect();

    expect(PayChange::query()->count())->toBe(0);
});

it('records a promotion, updates the position and adds the new rate', function () {
    $employee = worker(['position' => 'Clerk I']);
    PayChange::factory()->create(['employee_id' => $employee->id, 'amount_centavos' => 45_000]);

    $this->post("/employees/{$employee->id}/positions", ['change_type' => 'Promotion', 'effective_on' => '2026-05-01', 'to_position' => 'Clerk II', 'amount' => '600', 'rate_type' => 'Daily'])
        ->assertRedirect("/employees/{$employee->id}#position-history");

    $change = PositionChange::query()->sole();

    expect($change->from_position)->toBe('Clerk I')
        ->and($employee->fresh()->position)->toBe('Clerk II')
        ->and($employee->employmentPeriods()->first()->position)->toBe('Clerk II')
        ->and(app(PayHistoryService::class)->rateOn($employee)->change_type)->toBe(PayChangeType::Promotion);

    $this->get("/employees/{$employee->id}")->assertSee('Promotions and demotions')->assertSee('from Clerk I');
    $this->get("/employees/{$employee->id}/print")->assertSee('Promotions and demotions')->assertSee('Clerk II');
});

it('records a demotion without changing the position until it takes effect', function () {
    $employee = worker(['position' => 'Clerk II']);

    app(PositionHistoryService::class)->record($employee, ['effective_on' => '2026-09-01', 'change_type' => 'Demotion', 'to_position' => 'Clerk I']);

    expect($employee->fresh()->position)->toBe('Clerk II');
});

it('puts the position back when the latest change is deleted', function () {
    $employee = worker(['position' => 'Clerk I']);
    $service = app(PositionHistoryService::class);
    $service->record($employee, ['effective_on' => '2025-01-01', 'change_type' => 'Promotion', 'to_position' => 'Clerk II']);
    $latest = $service->record($employee->fresh(), ['effective_on' => '2026-01-01', 'change_type' => 'Promotion', 'to_position' => 'Clerk III']);

    expect($latest->from_position)->toBe('Clerk II');

    $this->delete("/employees/{$employee->id}/positions/{$latest->id}")->assertRedirect();

    expect($employee->fresh()->position)->toBe('Clerk II');
});

it('refuses an incomplete promotion and leaves nothing behind when its rate is bad', function () {
    $employee = worker(['position' => 'Clerk I']);
    PayChange::factory()->create(['employee_id' => $employee->id, 'effective_on' => '2026-05-01']);
    $service = app(PositionHistoryService::class);

    expect(fn () => $service->record($employee, ['effective_on' => '2026-05-01', 'change_type' => 'Promotion', 'to_position' => '']))->toThrow(UserFacingException::class, 'new position')
        ->and(fn () => $service->record($employee, ['effective_on' => '2026-05-01', 'change_type' => 'Promotion', 'to_position' => 'Clerk II', 'amount_centavos' => 60_000, 'rate_type' => 'Daily']))->toThrow(UserFacingException::class, 'already a compensation record');

    expect(PositionChange::query()->count())->toBe(0)->and($employee->fresh()->position)->toBe('Clerk I');
});

it('starts a contract from the daily rate in the pay history', function () {
    $employee = worker();
    PayChange::factory()->create(['employee_id' => $employee->id, 'amount_centavos' => 52_500]);

    expect(app(ContractService::class)->defaults($employee, ContractSetting::current())['daily_wage'])->toBe('525.00');

    $monthly = worker();
    PayChange::factory()->create(['employee_id' => $monthly->id, 'rate_type' => RateType::Monthly, 'amount_centavos' => 2_000_000]);
    Contract::factory()->create(['employee_id' => $monthly->id, 'daily_wage_centavos' => 44_000]);

    expect(app(ContractService::class)->defaults($monthly, ContractSetting::current())['daily_wage'])->toBe('440.00');
});

it('imports and exports wage orders, pay history and promotions', function () {
    $employee = worker(['employee_id' => 'EMP-77']);
    $spreadsheet = new Spreadsheet;
    $spreadsheet->removeSheetByIndex(0);
    $sheets = [
        'Wage Orders' => [['Region', 'Wage Order No.', 'Sector', 'Daily Minimum Wage', 'Effective Date'], ['Region XI', 'RB XI-23', 'Non-agriculture', 510.5, '2026-01-01']],
        'Compensation History' => [['Employee ID', 'Effective Date', 'Change Type', 'Rate Type', 'Basic Rate', 'Premium %', 'Reference'], ['EMP-77', '2026-01-01', 'Wage order', 'Daily', '1,020.00', '15', 'RB XI-23']],
        'Promotions' => [['Employee ID', 'Effective Date', 'Change Type', 'From Position', 'To Position'], ['EMP-77', '2026-02-01', 'Promotion', 'Clerk I', 'Clerk II']],
    ];

    foreach ($sheets as $title => $rows) {
        $sheet = $spreadsheet->createSheet()->setTitle($title);

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->getCell([$columnIndex + 1, $rowIndex + 1])->setValueExplicit($value, is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'pay').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $check = $this->post('/data/import', ['file' => new UploadedFile($path, 'pay.xlsx', test: true)])->assertOk()->assertSee('All rows passed every check');
    $this->post('/data/import/commit', ['token' => $check->viewData('token')])->assertRedirect('/data');

    expect(WageOrder::query()->sole()->daily_rate_centavos)->toBe(51_050)
        ->and(PayChange::query()->sole()->amount_centavos)->toBe(102_000)
        ->and(PayChange::query()->sole()->total_centavos)->toBe(117_300)
        ->and(PositionChange::query()->sole()->to_position)->toBe('Clerk II');

    $export = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
    file_put_contents($export, $this->get('/data/export?module=pay-history')->assertOk()->streamedContent());
    $sheet = IOFactory::load($export)->getSheetByName('Compensation History');
    $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1')[0];

    expect($sheet->getCell([array_search('Basic Rate', $headers, true) + 1, 2])->getValue())->toEqual(1020)
        ->and($sheet->getCell([1, 2])->getValue())->toBe('EMP-77')
        ->and($employee->payChanges()->count())->toBe(1);
});

it('adds the premium to the basic rate', function (int $basic, float $premium, int $total) {
    $change = PayChange::factory()->make(['amount_centavos' => $basic, 'premium_percent' => $premium]);

    expect($change->total_centavos)->toBe($total);
})->with([
    '2% premium' => [43_500, 2, 44_370],
    '15% premium' => [51_000, 15, 58_650],
    'latest Region XI rate' => [54_000, 15, 62_100],
    'no premium' => [2_500_000, 0, 2_500_000],
]);

it('shows the basic rate, premium and total on the profile and printout', function () {
    $employee = worker();
    PayChange::factory()->create(['employee_id' => $employee->id, 'effective_on' => '2026-03-13', 'amount_centavos' => 52_500, 'premium_percent' => 15]);

    $this->get("/employees/{$employee->id}")->assertOk()->assertSee('₱603.75')->assertSee('Basic ₱525.00 + 15% premium');
    $this->get("/employees/{$employee->id}/print")->assertOk()->assertSee('₱603.75')->assertSee('15% · ₱78.75');
});

it('rebuilds a rehired worker\'s rates from the regional steps, as in the COS record', function () {
    $this->travelTo('2026-10-01 10:00:00');
    app(RegionalWageSeeder::class)->run();

    $employee = worker(['date_hired' => '2017-04-01', 'last_day_of_service' => '2020-06-01', 'employment_status' => EmploymentStatus::Terminated]);
    app(EmploymentHistoryService::class)->rehire($employee, '2022-07-18', null);
    $service = app(PayHistoryService::class);

    foreach (WageOrder::query()->where('region', 'Region XI')->orderBy('effective_on')->get() as $order) {
        $service->apply($order, null, $order->effective_on->toDateString() >= '2025-03-07' ? 15 : 2);
    }

    $history = $employee->payChanges()->get();

    expect($history->map(fn (PayChange $change): array => [$change->effective_on->toDateString(), $change->amount_centavos, $change->total_centavos])->all())->toBe([
        ['2017-04-01', 31_700, 32_334],
        ['2017-05-01', 34_000, 34_680],
        ['2018-08-16', 37_000, 37_740],
        ['2019-02-16', 39_600, 40_392],
        ['2022-07-18', 42_700, 43_554],
        ['2023-01-01', 44_300, 45_186],
        ['2024-03-06', 46_200, 47_124],
        ['2024-09-01', 48_100, 49_062],
        ['2025-03-07', 51_000, 58_650],
        ['2026-03-13', 52_500, 60_375],
        ['2026-09-01', 54_000, 62_100],
    ]);
});

it('records the Caraga rates under Region XIII and is safe to run twice', function () {
    app(RegionalWageSeeder::class)->run();
    app(RegionalWageSeeder::class)->run();

    expect(WageOrder::query()->where('region', 'Region XI')->count())->toBe(11)
        ->and(WageOrder::query()->where('region', 'Region XIII')->orderByDesc('effective_on')->first()->daily_rate_centavos)->toBe(47_500);

    $page = $this->get('/wage-orders')->assertOk()->assertSee('Rate from Mar 13, 2026');

    expect($page->viewData('inForce')->get('Region XI')->daily_rate_centavos)->toBe(52_500);
});

it('keeps each person\'s premium unless a new one is given', function () {
    $keeps = worker();
    PayChange::factory()->create(['employee_id' => $keeps->id, 'premium_percent' => 2]);
    $first = WageOrder::factory()->create(['effective_on' => '2026-02-01', 'daily_rate_centavos' => 50_000]);
    $second = WageOrder::factory()->create(['effective_on' => '2026-03-01', 'daily_rate_centavos' => 52_000]);

    $this->post("/wage-orders/{$first->id}/apply");
    expect((float) app(PayHistoryService::class)->rateOn($keeps, '2026-02-01')->premium_percent)->toBe(2.0);

    $this->post("/wage-orders/{$second->id}/apply", ['premium_percent' => '150'])->assertSessionHas('flash.type', 'error');
    $this->post("/wage-orders/{$second->id}/apply", ['premium_percent' => '15']);
    expect((float) app(PayHistoryService::class)->rateOn($keeps)->premium_percent)->toBe(15.0);
});

it('gives a worker with no rate the default premium', function () {
    ContractSetting::current()->update(['premium_percent' => 15]);
    $worker = worker();
    app(PayHistoryService::class)->apply(WageOrder::factory()->create(['effective_on' => '2026-02-01']));

    expect((float) app(PayHistoryService::class)->rateOn($worker)->premium_percent)->toBe(15.0);
});

it('saves the premium on a salary increase and refuses a bad one', function () {
    $employee = worker();

    $this->post("/employees/{$employee->id}/pay", payForm(['premium_percent' => '15']));
    expect(app(PayHistoryService::class)->rateOn($employee)->total_centavos)->toBe(59_858);

    $this->post("/employees/{$employee->id}/pay", payForm(['effective_on' => '2026-04-01', 'premium_percent' => '200']))->assertSessionHas('flash.type', 'error');
    expect(PayChange::query()->count())->toBe(1);
});

it('moves a worker from COS to Regular with a promotion, and back if it is deleted', function () {
    $employee = worker(['position' => 'Letter Carrier']);

    $this->post("/employees/{$employee->id}/positions", ['change_type' => 'Promotion', 'effective_on' => '2026-05-01', 'to_position' => 'Postmaster', 'to_classification' => 'Regular']);

    $change = PositionChange::query()->sole();

    expect($employee->fresh()->classification)->toBe(Classification::Regular)
        ->and($change->from_classification)->toBe(Classification::Cos);

    $this->get("/employees/{$employee->id}")->assertSee('now Regular');
    $this->get("/employees/{$employee->id}/print")->assertSee('COS → Regular');

    $this->delete("/employees/{$employee->id}/positions/{$change->id}");

    expect($employee->fresh())->position->toBe('Letter Carrier')->classification->toBe(Classification::Cos);
});

it('carries the current premium onto a promotion rate', function () {
    $employee = worker();
    PayChange::factory()->create(['employee_id' => $employee->id, 'premium_percent' => 15]);

    app(PositionHistoryService::class)->record($employee, ['effective_on' => '2026-05-01', 'change_type' => 'Promotion', 'to_position' => 'Clerk II', 'amount_centavos' => 60_000, 'rate_type' => 'Daily']);

    expect(app(PayHistoryService::class)->rateOn($employee)->total_centavos)->toBe(69_000);
});

it('keeps wage orders away from signed-out visitors', function () {
    auth()->logout();

    $this->get('/wage-orders')->assertRedirectContains('/login');
});
