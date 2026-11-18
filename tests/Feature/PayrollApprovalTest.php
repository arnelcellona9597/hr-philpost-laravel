<?php

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\PayrollStatus;
use App\Enums\Region;
use App\Models\Branch;
use App\Models\ContractSetting;
use App\Models\Employee;
use App\Models\PayChange;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\User;
use App\Models\WageOrder;
use App\Services\PayrollService;
use App\Support\PayrollMonthly;

beforeEach(function () {
    $this->admin = User::factory()->systemAdmin()->create();
    $this->staff = User::factory()->create();
    $this->branch = Branch::factory()->create(['name' => 'Gen. Santos City', 'region' => Region::RegionXii]);
    WageOrder::factory()->create(['region' => 'Region XII', 'order_number' => 'RB XII-24', 'sector' => 'Non-agriculture', 'daily_rate_centavos' => 40_300, 'effective_on' => '2025-10-01']);
    WageOrder::factory()->create(['region' => 'Region XII', 'order_number' => 'RB XII-25', 'sector' => 'Non-agriculture', 'daily_rate_centavos' => 46_000, 'effective_on' => '2026-10-10']);
    ContractSetting::current()->update(['premium_percent' => 15]);
});

function semiMonthly(int $half = 1): PayrollPeriod
{
    return PayrollPeriod::query()->create([...PayrollPeriod::forHalf(2026, 10, $half), 'pay_date' => '2026-10-20']);
}

function gensanWorker(array $attributes = []): Employee
{
    return Employee::factory()->create(['classification' => Classification::Cos, 'employment_status' => EmploymentStatus::Active, 'station_id' => test()->branch->id, 'region' => null, ...$attributes]);
}

it('pays the regional minimum of the station region, with the configured premium, when there is no contract rate', function () {
    $worker = gensanWorker();
    $first = semiMonthly(1);
    $second = semiMonthly(2);

    app(PayrollService::class)->generate($first);
    app(PayrollService::class)->generate($second);

    $before = Payslip::query()->where('period_id', $first->id)->sole();
    $after = Payslip::query()->where('period_id', $second->id)->sole();

    expect($before->rate_source)->toBe('regional')
        ->and($before->region)->toBe('Region XII')
        ->and($before->daily_rate_centavos)->toBe(46_000)
        ->and((float) $before->premium_percent)->toBe(15.0)
        ->and($before->cosPay()['total_daily_rate'])->toBe(52_900)
        ->and($after->wage_order_id)->toBe(WageOrder::query()->where('order_number', 'RB XII-25')->value('id'))
        ->and($worker->payChanges()->count())->toBe(0);
});

it('uses an older wage order for a period that ended before the new one took effect', function () {
    gensanWorker();
    $period = PayrollPeriod::query()->create([...PayrollPeriod::forHalf(2026, 9, 2), 'pay_date' => '2026-10-05']);

    app(PayrollService::class)->generate($period);

    expect(Payslip::query()->sole()->daily_rate_centavos)->toBe(40_300);
});

it('keeps the contract rate and flags it when it is below the regional minimum', function () {
    $worker = gensanWorker();
    PayChange::factory()->create(['employee_id' => $worker->id, 'effective_on' => '2026-01-01', 'amount_centavos' => 43_000, 'premium_percent' => 10]);
    $period = semiMonthly(2);

    app(PayrollService::class)->generate($period);
    $slip = Payslip::query()->sole();

    expect($slip->rate_source)->toBe('contract')
        ->and($slip->daily_rate_centavos)->toBe(43_000)
        ->and((float) $slip->premium_percent)->toBe(10.0)
        ->and($slip->regional_minimum_centavos)->toBe(46_000)
        ->and($slip->isBelowMinimum())->toBeTrue();

    signIn($this->admin)->get("/payroll/{$period->id}")->assertOk()
        ->assertSee('Regional minimum wage and premium')->assertSee('RB XII-25')->assertSee('below minimum')->assertSee('₱529.00');
});

it('moves through review, approval and release, and only a System Admin approves and releases', function () {
    gensanWorker();
    $period = semiMonthly(2);
    app(PayrollService::class)->generate($period);
    $slip = Payslip::query()->sole();
    app(PayrollService::class)->saveRows($period, [['id' => $slip->id, 'values' => ['days' => '11']]]);

    signIn($this->staff);
    $this->post("/payroll/{$period->id}/review")->assertSessionHas('flash.type', 'success');
    expect($period->fresh()->status)->toBe(PayrollStatus::Reviewed)
        ->and($period->fresh()->reviewed_by)->toBe($this->staff->id);

    $this->post("/payroll/{$period->id}/approve")->assertForbidden();
    $this->post("/payroll/{$period->id}/release")->assertForbidden();
    $this->post("/payroll/{$period->id}/return-to-draft")->assertForbidden();

    // Read-only while reviewed.
    $this->put("/payroll/{$period->id}/grid", ['grid' => json_encode([['id' => $slip->id, 'values' => ['days' => '5']]])])->assertSessionHasErrors('grid');
    $this->post("/payroll/{$period->id}/generate")->assertSessionHas('flash.type', 'error');
    expect($slip->fresh()->days_worked)->toBe('11.00');

    signIn($this->admin);
    $this->post("/payroll/{$period->id}/release")->assertSessionHas('flash.type', 'error');
    $this->post("/payroll/{$period->id}/approve")->assertSessionHas('flash.type', 'success');
    expect($period->fresh()->status)->toBe(PayrollStatus::Approved)
        ->and($period->fresh()->approved_by)->toBe($this->admin->id);

    $this->post("/payroll/{$period->id}/release")->assertSessionHas('flash.type', 'success');
    expect($period->fresh()->isReleased())->toBeTrue();
});

it('sends a reviewed payroll back to draft so it can be changed', function () {
    gensanWorker();
    $period = semiMonthly(1);
    app(PayrollService::class)->generate($period);
    app(PayrollService::class)->review($period, $this->staff);

    signIn($this->admin)->post("/payroll/{$period->id}/return-to-draft")->assertSessionHas('flash.type', 'success');

    expect($period->fresh()->isDraft())->toBeTrue()
        ->and($period->fresh()->reviewed_at)->toBeNull();
});

it('refuses approval with a missing rate, missing days or an unconfirmed rate below the minimum', function () {
    $period = semiMonthly(2);
    $noRate = Employee::factory()->create(['classification' => Classification::Cos, 'employment_status' => EmploymentStatus::Active, 'station_id' => Branch::factory()->create(['region' => Region::Barmm])->id, 'region' => null]);
    app(PayrollService::class)->generate($period);
    app(PayrollService::class)->review($period, $this->staff);

    signIn($this->admin)->post("/payroll/{$period->id}/approve")->assertSessionHas('flash.message', fn (string $message) => str_contains($message, 'have no daily rate'));

    app(PayrollService::class)->returnToDraft($period);
    Payslip::query()->where('employee_id', $noRate->id)->delete();
    $noRate->update(['employment_status' => EmploymentStatus::Resigned]);
    $worker = gensanWorker();
    PayChange::factory()->create(['employee_id' => $worker->id, 'effective_on' => '2026-01-01', 'amount_centavos' => 43_000]);
    app(PayrollService::class)->generate($period);
    app(PayrollService::class)->review($period, $this->staff);

    $this->post("/payroll/{$period->id}/approve")->assertSessionHas('flash.message', fn (string $message) => str_contains($message, 'no pay'));

    app(PayrollService::class)->returnToDraft($period);
    $slip = Payslip::query()->sole();
    app(PayrollService::class)->saveRows($period, [['id' => $slip->id, 'values' => ['days' => '11']]]);
    app(PayrollService::class)->review($period, $this->staff);

    $this->post("/payroll/{$period->id}/approve")->assertSessionHas('flash.message', fn (string $message) => str_contains($message, 'below the regional minimum'));
    $this->post("/payroll/{$period->id}/approve", ['confirm_below_minimum' => '1'])->assertSessionHas('flash.type', 'success');

    expect($period->fresh()->status)->toBe(PayrollStatus::Approved);
});

it('lets only a System Admin correct released payroll', function () {
    gensanWorker();
    $period = semiMonthly(1);
    app(PayrollService::class)->generate($period);
    $slip = Payslip::query()->sole();
    app(PayrollService::class)->saveRows($period, [['id' => $slip->id, 'values' => ['days' => '11']]]);
    $period->forceFill(['status' => PayrollStatus::Approved])->save();
    app(PayrollService::class)->release($period, $this->admin, false);

    signIn($this->staff)->put("/payroll/{$period->id}/grid", ['grid' => json_encode([['id' => $slip->id, 'values' => ['days' => '10']]])])->assertForbidden();
    signIn($this->admin)->put("/payroll/{$period->id}/grid", ['grid' => json_encode([['id' => $slip->id, 'values' => ['days' => '10']]])])->assertSessionHasNoErrors();

    expect($slip->fresh()->corrected_after_release)->toBeTrue();
});

it('summarises both halves of a month side by side, each with its own amounts and status', function () {
    $worker = gensanWorker(['last_name' => 'Alegre']);
    $first = semiMonthly(1);
    $second = semiMonthly(2);

    foreach ([[$first, '11'], [$second, '10']] as [$period, $days]) {
        app(PayrollService::class)->generate($period);
        $slip = $period->payslips()->sole();
        app(PayrollService::class)->saveRows($period, [['id' => $slip->id, 'values' => ['days' => $days, 'hdmf' => '200']]]);
    }

    $second->forceFill(['status' => PayrollStatus::Reviewed])->save();
    $summary = PayrollMonthly::build(2026, 10);
    $row = $summary['rows'][0];

    expect($summary['title'])->toBe('OCTOBER 1-31, 2026')
        ->and($summary['periods'][1]->id)->toBe($first->id)
        ->and($summary['periods'][2]->status)->toBe(PayrollStatus::Reviewed)
        ->and($row['halves'][1]['days'])->toBe(11.0)
        ->and($row['halves'][2]['days'])->toBe(10.0)
        ->and($row['halves'][1]['gross'])->toBe(52_900 * 11)
        ->and($row['halves'][2]['gross'])->toBe(52_900 * 10)
        ->and($row['total']['net'])->toBe($first->payslips()->sole()->net_pay_centavos + $second->payslips()->sole()->net_pay_centavos)
        ->and($summary['totals']['month']['deductions'])->toBe(40_000);

    signIn($this->staff);
    $this->get('/payroll/monthly/2026/10')->assertOk()->assertSee('MONTHLY PAYROLL SUMMARY')->assertSee('ALEGRE')->assertSee('Reviewed');
    $this->get('/payroll/monthly/2026/10/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get('/payroll/monthly/2026/10/export')->assertOk();
    $this->get('/payroll?year=2026&month=10&half=2')->assertOk()->assertSee('2nd half (16–31)')->assertDontSee('October 2026 — 1st half (1–15)');
});
