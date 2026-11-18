<?php

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\MessageCategory;
use App\Enums\PayrollStatus;
use App\Enums\RecipientStatus;
use App\Exceptions\UserFacingException;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\NotificationSetting;
use App\Models\PayChange;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipSetting;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\PdfRenderer;
use App\Support\CosPay;

function payrollService(): PayrollService
{
    return app(PayrollService::class);
}

function paidSlip(PayrollPeriod $period, array $attributes = []): Payslip
{
    return Payslip::factory()->create(['period_id' => $period->id, ...$attributes]);
}

function approved(PayrollPeriod $period): PayrollPeriod
{
    $period->forceFill(['status' => PayrollStatus::Approved])->save();

    return $period;
}

function releasedPeriod(): PayrollPeriod
{
    $period = PayrollPeriod::factory()->create();
    paidSlip($period);
    payrollService()->release(approved($period), User::factory()->create(), false);

    return $period->fresh();
}

function grid(array $rows): array
{
    return ['grid' => json_encode($rows)];
}

beforeEach(function () {
    $this->hr = User::factory()->systemAdmin()->create();
    signIn($this->hr);
});

it('creates the 1st and 2nd half of a month and refuses the same half twice', function () {
    $this->post('/payroll', ['year' => '2026', 'month' => '10', 'half' => '1', 'pay_date' => '2026-10-20'])->assertRedirect();
    $this->post('/payroll', ['year' => '2026', 'month' => '10', 'half' => '2', 'pay_date' => '2026-11-05'])->assertRedirect();

    [$first, $second] = PayrollPeriod::query()->orderBy('period_start')->get()->all();

    expect($first->status)->toBe(PayrollStatus::Draft)
        ->and([$first->period_start->toDateString(), $first->period_end->toDateString(), $first->code])->toBe(['2026-10-01', '2026-10-15', '2026-10-1'])
        ->and([$second->period_start->toDateString(), $second->period_end->toDateString(), $second->code])->toBe(['2026-10-16', '2026-10-31', '2026-10-2'])
        ->and($first->name)->toBe('October 2026 — 1st half (1–15)')
        ->and($second->name)->toBe('October 2026 — 2nd half (16–31)');

    $this->post('/payroll', ['year' => '2026', 'month' => '10', 'half' => '1', 'pay_date' => '2026-10-20'])->assertSessionHasErrors('half');

    // A period made before semi-monthly periods, with other wording, still counts as that half.
    PayrollPeriod::factory()->create(['name' => 'November 1-15,2026', 'code' => '1ST', 'period_start' => '2026-11-01', 'period_end' => '2026-11-15']);
    $this->post('/payroll', ['year' => '2026', 'month' => '11', 'half' => '1', 'pay_date' => '2026-11-20'])->assertSessionHasErrors('half');
    $this->post('/payroll', ['year' => '2026', 'month' => '13', 'half' => '3', 'pay_date' => ''])->assertSessionHasErrors(['month', 'half', 'pay_date']);

    expect(PayrollPeriod::query()->count())->toBe(3);
});

it('ends the 2nd half on the last day of every month', function (int $year, int $month, string $end) {
    expect(PayrollPeriod::forHalf($year, $month, 2)['period_end'])->toBe($end)
        ->and(PayrollPeriod::forHalf($year, $month, 2)['period_start'])->toBe(sprintf('%04d-%02d-16', $year, $month));
})->with([
    'February' => [2027, 2, '2027-02-28'],
    'February, leap year' => [2028, 2, '2028-02-29'],
    'April' => [2026, 4, '2026-04-30'],
    'October' => [2026, 10, '2026-10-31'],
]);

it('ignores a status sent with the period form', function () {
    $period = PayrollPeriod::factory()->create();

    $this->put("/payroll/{$period->id}", ['year' => '2026', 'month' => '3', 'half' => '2', 'pay_date' => '2026-04-05', 'status' => 'Released', 'released_at' => '2026-01-01']);

    expect($period->fresh()->name)->toBe('March 2026 — 2nd half (16–31)')
        ->and($period->fresh()->isReleased())->toBeFalse();
});

function payrollCosWorker(array $attributes = [], int $rate = 54_000, float $premium = 15): Employee
{
    $employee = Employee::factory()->create(['classification' => Classification::Cos, 'employment_status' => EmploymentStatus::Active, ...$attributes]);
    PayChange::factory()->create(['employee_id' => $employee->id, 'effective_on' => '2026-01-01', 'amount_centavos' => $rate, 'premium_percent' => $premium]);

    return $employee;
}

it('computes every column exactly as the payroll workbook does', function () {
    $base = ['daily_rate_centavos' => 54_000, 'premium_percent' => 15, 'days_worked' => 11, 'late_minutes' => 32, 'ot_hours' => 0, 'ot_rest_hours' => 0, 'sss_centavos' => 0, 'hdmf_centavos' => 0, 'cmpeai_centavos' => 0, 'absences' => 0];

    expect(CosPay::compute($base))->toMatchArray([
        'premium' => 8_100, 'total_daily_rate' => 62_100, 'gross' => 683_100, 'rate_per_minute' => 129,
        'late' => 4_128, 'hourly_rate' => 9_703, 'hourly_rest_rate' => 11_644, 'overtime' => 0,
        'gross_after_late' => 678_972, 'net' => 678_972,
    ]);

    // The workbook's sample: P460, 11 days, SSS 750 and HDMF 400, with overtime on both kinds of day.
    $pay = CosPay::compute([...$base, 'daily_rate_centavos' => 46_000, 'late_minutes' => 66, 'ot_hours' => 2.5, 'ot_rest_hours' => 1, 'sss_centavos' => 75_000, 'hdmf_centavos' => 40_000, 'absences' => 1]);

    expect($pay['total_daily_rate'])->toBe(52_900)
        ->and($pay['rate_per_minute'])->toBe(110)          // ROUND(529/480, 2) = 1.10
        ->and($pay['hourly_rate'])->toBe(8_266)            // ROUND(529/8*1.25, 2) = 82.66
        ->and($pay['hourly_rest_rate'])->toBe(9_919)       // ROUND(529/8*1.5, 2) = 99.19
        ->and($pay['overtime'])->toBe(30_584)              // 2.5*82.66 + 1*99.19 = 305.84
        ->and($pay['net'])->toBe(581_900 - 7_260 + 30_584 - 115_000)
        ->and($pay['excess'])->toBe(52_900);
});

it('rounds half away from zero like Excel, only where the workbook rounds', function () {
    // 1.25 hours at 82.66 is 103.325: the overtime column shows 103.33, net pay rounds the exact sum once.
    $pay = CosPay::compute(['daily_rate_centavos' => 46_000, 'premium_percent' => 15, 'days_worked' => 0, 'late_minutes' => 0, 'ot_hours' => 1.25, 'ot_rest_hours' => 0, 'sss_centavos' => 0, 'hdmf_centavos' => 0, 'cmpeai_centavos' => 0]);

    expect($pay['overtime'])->toBe(10_333)
        ->and($pay['net'])->toBe(10_333);
});

it('adds the active COS workers with their rate, premium and standing deductions', function () {
    $period = PayrollPeriod::factory()->create(['period_start' => '2026-10-16', 'period_end' => '2026-10-31', 'batch' => '7']);
    $branch = Branch::factory()->create(['name' => 'DCCPO']);
    $worker = payrollCosWorker(['station_id' => $branch->id, 'sss_deduction_centavos' => 75_000, 'hdmf_deduction_centavos' => 40_000], 54_000, 15);
    payrollCosWorker(['employment_status' => EmploymentStatus::Resigned]);
    Employee::factory()->create(['classification' => Classification::Regular, 'employment_status' => EmploymentStatus::Active]);
    $noRate = Employee::factory()->create(['classification' => Classification::Cos, 'employment_status' => EmploymentStatus::Active]);

    $this->post("/payroll/{$period->id}/generate")->assertSessionHas('flash.type', 'info');

    $slip = Payslip::query()->where('employee_id', $worker->id)->sole();

    expect(Payslip::query()->pluck('employee_id')->sort()->values()->all())->toBe(collect([$worker->id, $noRate->id])->sort()->values()->all())
        ->and($slip->station)->toBe('DCCPO')
        ->and($slip->daily_rate_centavos)->toBe(54_000)
        ->and((float) $slip->premium_percent)->toBe(15.0)
        ->and($slip->sss_centavos)->toBe(75_000)
        ->and($slip->hdmf_centavos)->toBe(40_000)
        ->and($slip->period_covered)->toBe('10/16-31/2026')
        ->and($slip->batch_label)->toBe('7')
        ->and($slip->gross_pay_centavos)->toBe(0)
        ->and(Payslip::query()->where('employee_id', $noRate->id)->sole()->daily_rate_centavos)->toBe(0)
        ->and(payrollService()->generate($period))->toBe(['created' => 0, 'existing' => 2, 'without_rate' => 0, 'regional' => 0]);
});

it('adds only the chosen branches', function () {
    $period = PayrollPeriod::factory()->create();
    $branch = Branch::factory()->create();
    $match = payrollCosWorker(['station_id' => $branch->id]);
    payrollCosWorker();

    $this->post("/payroll/{$period->id}/generate", ['branch' => [$branch->id]]);

    expect(Payslip::query()->sole()->employee_id)->toBe($match->id);
});

it('refuses to generate on a released period', function () {
    $period = releasedPeriod();
    payrollCosWorker();
    $before = Payslip::query()->count();

    $this->post("/payroll/{$period->id}/generate")->assertSessionHas('flash.type', 'error');

    expect(Payslip::query()->count())->toBe($before);
});

it('saves the grid, computes the pay and leaves the standing deductions alone', function () {
    $period = PayrollPeriod::factory()->create();
    $worker = payrollCosWorker(['sss_deduction_centavos' => 75_000]);
    payrollService()->generate($period);
    $slip = Payslip::query()->sole();

    $this->put("/payroll/{$period->id}/grid", grid([['id' => $slip->id, 'values' => ['days' => '11', 'late' => '32', 'ot' => '', 'ot_rest' => '', 'sss' => '0', 'hdmf' => '400', 'cmpeai' => '', 'covered' => '10/1-15/2026', 'specific' => 'Tupi', 'absences' => '1', 'tardiness' => '6']]]))
        ->assertSessionHasNoErrors();

    $slip->refresh();

    expect($slip->days_worked)->toBe('11.00')
        ->and($slip->late_minutes)->toBe(32)
        ->and($slip->specific_station)->toBe('Tupi')
        ->and($slip->period_covered)->toBe('10/1-15/2026')
        ->and($slip->gross_pay_centavos)->toBe(683_100)
        ->and($slip->total_deductions_centavos)->toBe(4_128 + 40_000)
        ->and($slip->net_pay_centavos)->toBe(678_972 - 40_000)
        ->and($slip->net_pay_centavos)->toBe($slip->cosPay()['net'])
        ->and(array_column($slip->deductions, 'label'))->toBe(['Late', 'SSS', 'HDMF', 'CMPEAI'])
        ->and($worker->fresh()->sss_deduction_centavos)->toBe(75_000);
});

it('saves nothing when one row of the grid is wrong', function (array $values, string $message) {
    $period = PayrollPeriod::factory()->create();
    payrollCosWorker(['last_name' => 'Good']);
    payrollCosWorker(['last_name' => 'Bad']);
    payrollService()->generate($period);
    [$good, $bad] = [Payslip::query()->where('full_name', 'like', 'Good%')->sole(), Payslip::query()->where('full_name', 'like', 'Bad%')->sole()];

    $this->put("/payroll/{$period->id}/grid", grid([
        ['id' => $good->id, 'values' => ['days' => '10']],
        ['id' => $bad->id, 'values' => $values],
    ]))->assertSessionHasErrors('grid');

    expect(session('errors')->get('grid')[0])->toContain($message)
        ->and($good->fresh()->days_worked)->toBe('0.00');
})->with([
    'negative days' => [['days' => '-5'], 'is not a number from 0 to 999.99'],
    'three decimals' => [['ot' => '1.005'], 'is not a number from 0 to 999.99'],
    'fraction of a minute' => [['late' => '2.5'], 'is not a whole number of minutes'],
    'bad amount' => [['sss' => 'abc'], 'is not an amount'],
    'net below zero' => [['days' => '1', 'sss' => '5000'], 'net pay would be negative'],
]);

it('refreshes rates from pay history after a wage change', function () {
    $period = PayrollPeriod::factory()->create(['period_start' => '2026-10-16', 'period_end' => '2026-10-31']);
    $worker = payrollCosWorker([], 46_000);
    payrollService()->generate($period);
    $slip = Payslip::query()->sole();
    payrollService()->saveRows($period, [['id' => $slip->id, 'values' => ['days' => '10']]]);

    PayChange::factory()->create(['employee_id' => $worker->id, 'effective_on' => '2026-10-20', 'amount_centavos' => 54_000, 'premium_percent' => 15]);

    $this->post("/payroll/{$period->id}/refresh-rates")->assertSessionHas('flash.type', 'success');

    expect($slip->fresh()->daily_rate_centavos)->toBe(54_000)
        ->and($slip->fresh()->gross_pay_centavos)->toBe(621_000);
});

it('refuses a grid with no amounts and a payslip from another period', function () {
    $period = PayrollPeriod::factory()->create();
    $other = paidSlip(PayrollPeriod::factory()->create());

    $this->put("/payroll/{$period->id}/grid", ['grid' => 'nope'])->assertSessionHasErrors('form');
    $this->put("/payroll/{$period->id}/grid", grid([['id' => $other->id, 'values' => []]]))->assertSessionHasErrors('grid');
});

it('refuses to release an empty period or one with zero gross, and never twice', function () {
    $period = PayrollPeriod::factory()->create();

    expect(fn () => payrollService()->release($period, $this->hr, false))->toThrow(UserFacingException::class, 'Only an approved payroll can be released.');
    approved($period);
    expect(fn () => payrollService()->release($period, $this->hr, false))->toThrow(UserFacingException::class, 'There are no payslips in this period yet.');

    $zero = paidSlip($period, ['earnings' => [], 'deductions' => []]);

    expect(fn () => payrollService()->release($period, $this->hr, false))->toThrow(UserFacingException::class, '1 payslip(s) have no earnings');

    $zero->delete();
    paidSlip($period);
    payrollService()->release(approved($period), $this->hr, false);

    expect($period->fresh()->isReleased())->toBeTrue()
        ->and($period->fresh()->released_by)->toBe($this->hr->id)
        ->and(fn () => payrollService()->release($period, $this->hr, true))->toThrow(UserFacingException::class, 'already been released');
});

it('queues one frozen payslip email per employee on release and records those without an address', function () {
    $period = PayrollPeriod::factory()->create(['name' => 'Oct A', 'pay_date' => '2026-10-20']);
    $withEmail = Employee::factory()->create(['first_name' => 'Ana', 'email' => 'ana@example.test']);
    $noEmail = Employee::factory()->create(['email' => null]);
    $slip = paidSlip($period, ['employee_id' => $withEmail->id]);
    paidSlip($period, ['employee_id' => $noEmail->id]);

    approved($period);
    $this->post("/payroll/{$period->id}/release", ['notify' => '1'])->assertRedirect();

    $message = Message::query()->sole();
    $row = MessageRecipient::query()->where('email', 'ana@example.test')->sole();

    expect($message->category)->toBe(MessageCategory::Payroll)
        ->and($message->total)->toBe(2)
        ->and($message->skipped)->toBe(1)
        ->and($row->payslip_id)->toBe($slip->id)
        ->and($row->status)->toBe(RecipientStatus::Queued)
        ->and($row->subject)->toBe('Your payslip for Oct A')
        ->and($row->body)->toContain('Hi Ana')
        ->and(MessageRecipient::query()->where('status', RecipientStatus::Skipped)->count())->toBe(1);
});

it('sends no email when the box is unticked or the setting is off', function () {
    $period = PayrollPeriod::factory()->create();
    paidSlip(approved($period));
    $this->post("/payroll/{$period->id}/release");

    $second = PayrollPeriod::factory()->create();
    paidSlip(approved($second));
    NotificationSetting::current()->update(['payroll_released_to_employee' => false]);
    $this->post("/payroll/{$second->id}/release", ['notify' => '1']);

    expect(Message::query()->count())->toBe(0)
        ->and($second->fresh()->isReleased())->toBeTrue();
});

it('flags only real changes after release and resends with a Corrected subject', function () {
    $period = PayrollPeriod::factory()->create(['name' => 'Oct A']);
    $employee = Employee::factory()->create(['email' => 'ana@example.test']);
    $slip = paidSlip($period, ['employee_id' => $employee->id, 'daily_rate_centavos' => 54_000, 'premium_percent' => 15, 'days_worked' => 11]);
    $slip->update(['earnings' => [['label' => 'Gross', 'amount_centavos' => 683_100], ['label' => 'Overtime Pay', 'amount_centavos' => 0]], 'deductions' => [['label' => 'Late', 'amount_centavos' => 0], ['label' => 'SSS', 'amount_centavos' => 0], ['label' => 'HDMF', 'amount_centavos' => 0], ['label' => 'CMPEAI', 'amount_centavos' => 0]]]);
    payrollService()->release(approved($period), $this->hr, false);

    $this->put("/payroll/{$period->id}/grid", grid([['id' => $slip->id, 'values' => ['days' => '11']]]))->assertSessionHasNoErrors();
    expect($slip->fresh()->corrected_after_release)->toBeFalse();

    $this->put("/payroll/payslips/{$slip->id}", ['days' => '10', 'remarks' => 'fixed'])->assertSessionHasNoErrors();
    expect($slip->fresh()->corrected_after_release)->toBeTrue();

    $this->post("/payroll/payslips/{$slip->id}/resend")->assertRedirect();

    expect(MessageRecipient::query()->sole()->subject)->toBe('Corrected: Your payslip for Oct A')
        ->and($slip->fresh()->corrected_after_release)->toBeFalse();
});

it('keeps the correction flag when the employee has no address', function () {
    $period = PayrollPeriod::factory()->create();
    $slip = paidSlip($period, ['employee_id' => Employee::factory()->create(['email' => null])->id]);
    payrollService()->release(approved($period), $this->hr, false);
    $slip->forceFill(['corrected_after_release' => true])->save();

    $this->post("/payroll/payslips/{$slip->id}/resend")->assertSessionHas('flash.type', 'error');

    expect($slip->fresh()->corrected_after_release)->toBeTrue();
});

it('refuses to resend before release', function () {
    $slip = paidSlip(PayrollPeriod::factory()->create());

    $this->post("/payroll/payslips/{$slip->id}/resend")->assertSessionHas('flash.type', 'error');

    expect(Message::query()->count())->toBe(0);
});

it('enforces the delete rules', function () {
    $draft = PayrollPeriod::factory()->create();
    $slip = paidSlip($draft);
    paidSlip($draft);

    $this->delete("/payroll/{$draft->id}")->assertSessionHas('flash.type', 'error');
    expect($draft->fresh())->not->toBeNull();

    $this->delete("/payroll/payslips/{$slip->id}")->assertRedirect("/payroll/{$draft->id}");
    $this->delete("/payroll/{$draft->id}/payslips")->assertRedirect();
    expect($draft->payslips()->count())->toBe(0);

    $this->delete("/payroll/{$draft->id}")->assertRedirect('/payroll');
    expect($draft->fresh())->toBeNull();

    $released = releasedPeriod();
    $kept = $released->payslips()->sole();

    $this->delete("/payroll/{$released->id}")->assertSessionHas('flash.type', 'error');
    $this->delete("/payroll/{$released->id}/payslips")->assertSessionHas('flash.type', 'error');
    $this->delete("/payroll/payslips/{$kept->id}")->assertSessionHas('flash.type', 'error');

    expect($kept->fresh())->not->toBeNull();
});

it('points the delete-all button at the period, not at a single payslip', function () {
    $period = PayrollPeriod::factory()->create();
    paidSlip($period);

    $this->get("/payroll/{$period->id}")->assertOk()
        ->assertSee('action="'.url("/payroll/{$period->id}/payslips").'"', false)
        ->assertDontSee('action="'.url("/payroll/payslips/{$period->id}").'"', false);
});

it('keeps the route list cacheable, as php artisan optimize needs on the live server', function () {
    $this->artisan('route:cache')->assertSuccessful();
    $this->artisan('route:clear')->assertSuccessful();
});

it('shows the payroll pages', function () {
    $period = PayrollPeriod::factory()->create(['name' => 'Oct A']);
    $slip = paidSlip($period, ['full_name' => '<b>Reyes</b>', 'remarks' => 'Note']);

    $this->get('/payroll')->assertOk()->assertSee('Oct A')->assertSee('₱25,000.00');
    $this->get('/payroll/new')->assertOk();
    $this->get("/payroll/{$period->id}")->assertOk()->assertSee('₱23,749.50')->assertDontSee('<b>Reyes', false);
    $this->get("/payroll/{$period->id}/edit")->assertOk();
    $this->get("/payroll/{$period->id}/print")->assertOk()->assertSee('NET PAY');
    $this->get("/payroll/{$period->id}/register")->assertOk()->assertSee('PAYROLL OF CONTRACT OF SERVICE (COS)')->assertSee('GRAND TOTAL')->assertDontSee('<b>Reyes', false);
    $this->get("/payroll/{$period->id}/register/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get("/payroll/payslips/{$slip->id}")->assertOk()->assertSee('Save payslip');
    $this->get("/payroll/payslips/{$slip->id}/print")->assertOk()->assertSee('NET PAY');
    $this->get('/payroll/settings')->assertOk()->assertSee('Payslip template');
});

it('shows sample data on the template preview before any payslip exists', function () {
    $this->get('/payroll/settings')->assertOk()->assertSee('sample data')->assertSee('Dela Cruz, Juan P.');
});

it('saves the payslip template', function () {
    $this->put('/payroll/settings', [
        'title' => 'PAY',
        'earning_items' => "Basic\r\nBasic\r\n\r\nOT",
        'deduction_items' => 'Tax',
        'show_tin' => '1',
        'paper_size' => 'Half-Letter',
    ])->assertSessionHasNoErrors();

    $settings = PayslipSetting::current();

    expect($settings->earning_items)->toBe(['Basic', 'OT'])
        ->and($settings->shown_fields['tin'])->toBeTrue()
        ->and($settings->shown_fields['position'])->toBeFalse()
        ->and($settings->paper_size)->toBe('Half-Letter')
        ->and($settings->show_logo)->toBeFalse()
        ->and($settings->currency_symbol)->toBe('₱');

    $this->put('/payroll/settings', ['earning_items' => '', 'deduction_items' => 'Tax'])->assertSessionHasErrors(['form' => 'List at least one earning item and one deduction item.']);
    $this->put('/payroll/settings', ['earning_items' => str_repeat('x', 41), 'deduction_items' => 'Tax'])->assertSessionHasErrors('form');
});

it('renders payslip PDFs on every paper size', function (string $paper) {
    PayslipSetting::current()->update(['paper_size' => $paper, 'prepared_by_name' => 'A. Officer', 'address_lines' => "Line 1\nLine 2"]);
    $slip = paidSlip(PayrollPeriod::factory()->create(['code' => '2026-10-A']), ['employee_code' => 'EMP-1', 'remarks' => 'Note']);

    expect(app(PdfRenderer::class)->payslip($slip))->toStartWith('%PDF-')
        ->and(app(PdfRenderer::class)->payslipFilename($slip))->toBe('payslip-2026-10-A-EMP-1.pdf');

    $this->get("/payroll/payslips/{$slip->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
})->with(['A4', 'Letter', 'Half-Letter']);
