<?php

use App\Enums\PayChangeType;
use App\Enums\Region;
use App\Models\Employee;
use App\Models\PayChange;
use App\Models\WageOrder;

beforeEach(fn () => $this->travelTo('2026-11-08'));

/**
 * @return list<array{0: string, 1: int, 2: string}> date, rate in pesos, premium
 */
function payRows(Employee $employee): array
{
    return PayChange::query()->where('employee_id', $employee->id)->orderBy('effective_on')->get()
        ->map(fn (PayChange $change): array => [$change->effective_on->toDateString(), intdiv($change->amount_centavos, 100), $change->premium_percent])
        ->all();
}

it('gives a Region XI worker the rates from hiring on, 2% before March 13, 2026 and 15% after, as in the office record', function () {
    $morada = Employee::factory()->create(['classification' => 'COS', 'employment_status' => 'Active', 'region' => Region::RegionXi, 'date_hired' => '2024-08-19']);

    $this->artisan('hr:apply-minimum-wages', ['--commit' => true, '--force' => true])->assertSuccessful();

    expect(payRows($morada))->toBe([
        ['2024-08-19', 462, '2.00'],
        ['2024-09-01', 481, '2.00'],
        ['2025-03-07', 510, '2.00'],
        ['2026-03-13', 525, '15.00'],
        ['2026-09-01', 540, '15.00'],
    ])
        ->and(PayChange::query()->where('employee_id', $morada->id)->orderBy('effective_on')->first()->change_type)->toBe(PayChangeType::Starting)
        ->and(PayChange::query()->where('employee_id', $morada->id)->whereNotNull('wage_order_id')->count())->toBe(4)
        ->and(WageOrder::query()->where('region', 'Region XI')->count())->toBe(11);
});

it('switches Caraga to 15% from January 2026', function () {
    $ariate = Employee::factory()->create(['classification' => 'COS', 'employment_status' => 'Active', 'region' => Region::RegionXiii, 'date_hired' => '2025-07-21']);

    $this->artisan('hr:apply-minimum-wages', ['--commit' => true, '--force' => true])->assertSuccessful();

    expect(payRows($ariate))->toBe([
        ['2025-07-21', 435, '2.00'],
        ['2026-01-01', 455, '15.00'],
        ['2026-05-01', 475, '15.00'],
    ]);

    $current = PayChange::query()->where('employee_id', $ariate->id)->orderByDesc('effective_on')->first();
    expect($current->premium_centavos)->toBe(7125)->and($current->total_centavos)->toBe(54625);
});

it('starts someone hired before the first known rate at that rate', function () {
    $employee = Employee::factory()->create(['classification' => 'COS', 'employment_status' => 'Active', 'region' => Region::RegionXi, 'date_hired' => '2009-06-24']);

    $this->artisan('hr:apply-minimum-wages', ['--commit' => true, '--force' => true])->assertSuccessful();

    expect(payRows($employee)[0])->toBe(['2017-04-01', 317, '2.00'])->and(payRows($employee))->toHaveCount(11);
});

it('leaves out people who are not active COS, already have records, or have no region rates', function () {
    $regular = Employee::factory()->create(['classification' => 'Regular', 'employment_status' => 'Active', 'region' => Region::RegionXi, 'date_hired' => '2020-01-01']);
    $resigned = Employee::factory()->create(['classification' => 'COS', 'employment_status' => 'Resigned', 'region' => Region::RegionXi, 'date_hired' => '2020-01-01']);
    $done = Employee::factory()->create(['classification' => 'COS', 'employment_status' => 'Active', 'region' => Region::RegionXi, 'date_hired' => '2020-01-01']);
    PayChange::factory()->create(['employee_id' => $done->id]);
    $barmm = Employee::factory()->create(['classification' => 'COS', 'employment_status' => 'Active', 'region' => Region::Barmm, 'date_hired' => '2020-01-01', 'station_id' => null]);

    $this->artisan('hr:apply-minimum-wages', ['--commit' => true, '--force' => true])
        ->expectsOutputToContain('Left out, already has compensation records: 1')
        ->expectsOutputToContain('Left out, no rates for the region: 1')
        ->assertSuccessful();

    expect(PayChange::query()->whereIn('employee_id', [$regular->id, $resigned->id, $barmm->id])->count())->toBe(0)
        ->and(PayChange::query()->where('employee_id', $done->id)->count())->toBe(1);
});

it('changes nothing without --commit', function () {
    Employee::factory()->create(['classification' => 'COS', 'employment_status' => 'Active', 'region' => Region::RegionXi, 'date_hired' => '2024-08-19']);

    $this->artisan('hr:apply-minimum-wages')->expectsOutputToContain('Nothing was changed')->assertSuccessful();

    expect(PayChange::query()->count())->toBe(0)->and(WageOrder::query()->count())->toBe(0);
});
