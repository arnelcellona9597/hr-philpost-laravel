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
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipSetting;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\PdfRenderer;

function payrollService(): PayrollService
{
    return app(PayrollService::class);
}

function paidSlip(PayrollPeriod $period, array $attributes = []): Payslip
{
    return Payslip::factory()->create(['period_id' => $period->id, ...$attributes]);
}

function releasedPeriod(): PayrollPeriod
{
    $period = PayrollPeriod::factory()->create();
    paidSlip($period);
    payrollService()->release($period, User::factory()->create(), false);

    return $period->fresh();
}

function grid(array $rows): array
{
    return ['grid' => json_encode($rows)];
}

beforeEach(function () {
    $this->hr = User::factory()->create();
    signIn($this->hr);
});

it('creates a period and rejects bad dates and duplicate codes', function () {
    $this->post('/payroll', ['name' => 'Oct A', 'code' => '2026-10-A', 'period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'pay_date' => '2026-10-20'])->assertRedirect();

    $period = PayrollPeriod::query()->sole();
    expect($period->status)->toBe(PayrollStatus::Draft);

    $this->post('/payroll', ['name' => 'Bad', 'code' => '2026-10-B', 'period_start' => '2026-10-15', 'period_end' => '2026-10-01', 'pay_date' => '2026-10-20'])
        ->assertSessionHasErrors(['period_end' => 'Period end is before period start']);
    $this->post('/payroll', ['name' => 'Dup', 'code' => '2026-10-A', 'period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'pay_date' => '2026-10-20'])
        ->assertSessionHasErrors(['code' => 'That code is already used by another period.']);
});

it('ignores a status sent with the period form', function () {
    $period = PayrollPeriod::factory()->create();

    $this->put("/payroll/{$period->id}", ['name' => 'Renamed', 'status' => 'Released', 'released_at' => '2026-01-01']);

    expect($period->fresh()->name)->toBe('Renamed')
        ->and($period->fresh()->isReleased())->toBeFalse();
});

it('generates one payslip per active employee with the template items and a snapshot', function () {
    $period = PayrollPeriod::factory()->create();
    $branch = Branch::factory()->create(['name' => 'Main']);
    $employee = Employee::factory()->create(['station_id' => $branch->id, 'position' => 'Clerk', 'employment_status' => EmploymentStatus::Active]);
    Employee::factory()->create(['employment_status' => EmploymentStatus::Resigned]);

    $this->post("/payroll/{$period->id}/generate")->assertRedirect();

    $slip = Payslip::query()->sole();

    expect($slip->employee_id)->toBe($employee->id)
        ->and($slip->full_name)->toBe($employee->full_name)
        ->and($slip->station)->toBe('Main')
        ->and(array_column($slip->earnings, 'label'))->toBe(PayslipSetting::current()->earning_items)
        ->and($slip->gross_pay_centavos)->toBe(0);

    $employee->update(['position' => 'Director']);

    expect(payrollService()->generate($period))->toBe(['created' => 0, 'existing' => 1])
        ->and($slip->fresh()->position)->toBe('Clerk');
});

it('filters generation by branch and classification', function () {
    $period = PayrollPeriod::factory()->create();
    $branch = Branch::factory()->create();
    $match = Employee::factory()->create(['station_id' => $branch->id, 'classification' => Classification::Regular, 'employment_status' => EmploymentStatus::Active]);
    Employee::factory()->create(['station_id' => $branch->id, 'classification' => Classification::Cos, 'employment_status' => EmploymentStatus::Active]);
    Employee::factory()->create(['classification' => Classification::Regular, 'employment_status' => EmploymentStatus::Active]);

    $this->post("/payroll/{$period->id}/generate", ['branch' => [$branch->id], 'classification' => ['Regular']]);

    expect(Payslip::query()->sole()->employee_id)->toBe($match->id);
});

it('refuses to generate on a released period', function () {
    $period = releasedPeriod();
    Employee::factory()->create(['employment_status' => EmploymentStatus::Active]);
    $before = Payslip::query()->count();

    $this->post("/payroll/{$period->id}/generate")->assertSessionHas('flash.type', 'error');

    expect(Payslip::query()->count())->toBe($before);
});

it('saves the whole grid, keeps items outside the template and totals in centavos', function () {
    $period = PayrollPeriod::factory()->create();
    $slip = paidSlip($period, [
        'earnings' => [['label' => 'Old Bonus', 'amount_centavos' => 500]],
        'deductions' => [],
    ]);

    $this->put("/payroll/{$period->id}/grid", grid([['id' => $slip->id, 'values' => ['e:Basic Pay' => '₱10,000.10', 'e:PERA' => '0.20', 'd:Loans' => '100']]]))
        ->assertSessionHasNoErrors();

    $slip->refresh();

    expect($slip->gross_pay_centavos)->toBe(1_000_030 + 500)
        ->and($slip->total_deductions_centavos)->toBe(10_000)
        ->and($slip->net_pay_centavos)->toBe(1_000_530 - 10_000)
        ->and(array_column($slip->earnings, 'label'))->toContain('Old Bonus', 'Basic Pay');
});

it('saves nothing when one row of the grid is wrong', function (array $values, string $message) {
    $period = PayrollPeriod::factory()->create();
    $good = paidSlip($period, ['full_name' => 'Good, Row']);
    $bad = paidSlip($period, ['full_name' => 'Bad, Row']);
    $before = $good->earnings;

    $this->put("/payroll/{$period->id}/grid", grid([
        ['id' => $good->id, 'values' => ['e:Basic Pay' => '30000']],
        ['id' => $bad->id, 'values' => $values],
    ]))->assertSessionHasErrors('grid');

    expect(session('errors')->get('grid')[0])->toContain($message)
        ->and($good->fresh()->earnings)->toEqual($before);
})->with([
    'negative' => [['e:Basic Pay' => '-5'], 'cannot be negative'],
    'three decimals' => [['e:Basic Pay' => '1.005'], 'at most 2 decimal'],
    'not a number' => [['e:Basic Pay' => 'abc'], 'is not an amount'],
    'deductions over gross' => [['e:Basic Pay' => '10', 'd:Loans' => '11', 'd:PhilHealth' => '0'], 'deductions are higher than gross pay'],
]);

it('refuses a grid with no amounts and a payslip from another period', function () {
    $period = PayrollPeriod::factory()->create();
    $other = paidSlip(PayrollPeriod::factory()->create());

    $this->put("/payroll/{$period->id}/grid", ['grid' => 'nope'])->assertSessionHasErrors('form');
    $this->put("/payroll/{$period->id}/grid", grid([['id' => $other->id, 'values' => []]]))->assertSessionHasErrors('grid');
});

it('refuses to release an empty period or one with zero gross, and never twice', function () {
    $period = PayrollPeriod::factory()->create();

    expect(fn () => payrollService()->release($period, $this->hr, false))->toThrow(UserFacingException::class, 'There are no payslips in this period yet.');

    $zero = paidSlip($period, ['earnings' => [], 'deductions' => []]);

    expect(fn () => payrollService()->release($period, $this->hr, false))->toThrow(UserFacingException::class, '1 payslip(s) have no earnings');

    $zero->delete();
    paidSlip($period);
    payrollService()->release($period, $this->hr, false);

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
    paidSlip($period);
    $this->post("/payroll/{$period->id}/release");

    $second = PayrollPeriod::factory()->create();
    paidSlip($second);
    NotificationSetting::current()->update(['payroll_released_to_employee' => false]);
    $this->post("/payroll/{$second->id}/release", ['notify' => '1']);

    expect(Message::query()->count())->toBe(0)
        ->and($second->fresh()->isReleased())->toBeTrue();
});

it('flags only real changes after release and resends with a Corrected subject', function () {
    $period = PayrollPeriod::factory()->create(['name' => 'Oct A']);
    $employee = Employee::factory()->create(['email' => 'ana@example.test']);
    $slip = paidSlip($period, ['employee_id' => $employee->id]);
    payrollService()->release($period, $this->hr, false);

    $this->put("/payroll/{$period->id}/grid", grid([['id' => $slip->id, 'values' => ['e:Basic Pay' => '25000']]]))->assertSessionHasNoErrors();
    expect($slip->fresh()->corrected_after_release)->toBeFalse();

    $this->put("/payroll/payslips/{$slip->id}", ['e_0' => '26000', 'remarks' => 'fixed'])->assertSessionHasNoErrors();
    expect($slip->fresh()->corrected_after_release)->toBeTrue();

    $this->post("/payroll/payslips/{$slip->id}/resend")->assertRedirect();

    expect(MessageRecipient::query()->sole()->subject)->toBe('Corrected: Your payslip for Oct A')
        ->and($slip->fresh()->corrected_after_release)->toBeFalse();
});

it('keeps the correction flag when the employee has no address', function () {
    $period = PayrollPeriod::factory()->create();
    $slip = paidSlip($period, ['employee_id' => Employee::factory()->create(['email' => null])->id]);
    payrollService()->release($period, $this->hr, false);
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

it('shows the payroll pages', function () {
    $period = PayrollPeriod::factory()->create(['name' => 'Oct A']);
    $slip = paidSlip($period, ['full_name' => '<b>Reyes</b>', 'remarks' => 'Note']);

    $this->get('/payroll')->assertOk()->assertSee('Oct A')->assertSee('₱25,000.00');
    $this->get('/payroll/new')->assertOk();
    $this->get("/payroll/{$period->id}")->assertOk()->assertSee('₱23,749.50')->assertDontSee('<b>Reyes', false);
    $this->get("/payroll/{$period->id}/edit")->assertOk();
    $this->get("/payroll/{$period->id}/print")->assertOk()->assertSee('NET PAY');
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
