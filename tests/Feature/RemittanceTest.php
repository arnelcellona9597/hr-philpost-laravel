<?php

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\RemittanceKind;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Remittance;
use App\Models\RemittanceLine;
use App\Models\RemittanceSetting;
use App\Models\User;
use App\Models\VoucherSetting;
use App\Services\RemittanceService;
use App\Services\RemittancesWorkbook;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function cosWorker(array $attributes = []): Employee
{
    return Employee::factory()->create([
        'classification' => Classification::Cos,
        'employment_status' => EmploymentStatus::Active,
        'date_hired' => '2026-01-05',
        'sss' => fake()->numerify('##-#######-#'),
        'pagibig' => fake()->numerify('####-####-####'),
        ...$attributes,
    ]);
}

function gridRows(Remittance $remittance, ?callable $change = null): array
{
    $rows = $remittance->lines->map(fn (RemittanceLine $line): array => [
        'employee_id' => $line->employee_id,
        'last_name' => $line->last_name,
        'first_name' => $line->first_name,
        'middle_name' => $line->middle_name,
        'extension' => $line->extension,
        'birth_date' => $line->birth_date?->toDateString(),
        'station' => $line->station,
        'member_number' => $line->member_number,
        'amount' => number_format($line->amount_centavos / 100, 2, '.', ''),
        'employer_share' => number_format($line->employer_share_centavos / 100, 2, '.', ''),
        'compensation' => number_format($line->compensation_centavos / 100, 2, '.', ''),
        'remarks' => $line->remarks,
    ])->all();

    return $change === null ? $rows : $change($rows);
}

beforeEach(function () {
    Storage::fake('local');
    $this->hr = User::factory()->create(['name' => 'Rita Santos']);
    signIn($this->hr);
    $this->travelTo('2026-10-15 10:00:00');
});

it('writes amounts in words the way the voucher does', function (int $centavos, string $words) {
    expect(Money::words($centavos))->toBe($words);
})->with([
    [450_000, 'Four Thousand Five Hundred Pesos and 0/100 Only'],
    [960_000, 'Nine Thousand Six Hundred Pesos and 0/100 Only'],
    [2_584_128, 'Twenty Five Thousand Eight Hundred Forty One Pesos and 28/100 Only'],
    [100, 'One Peso and 0/100 Only'],
    [0, 'Zero Pesos and 0/100 Only'],
    [123_456_789_00, 'One Hundred Twenty Three Million Four Hundred Fifty Six Thousand Seven Hundred Eighty Nine Pesos and 0/100 Only'],
]);

it('starts the first month from active COS workers who have the member number', function () {
    $withNumber = cosWorker();
    cosWorker(['sss' => null]);
    cosWorker(['classification' => Classification::Regular]);
    cosWorker(['employment_status' => EmploymentStatus::Resigned, 'last_day_of_service' => '2026-08-31']);

    $this->post('/remittances', ['kind' => 'sss', 'year' => 2026, 'month' => 10])->assertRedirect();

    $remittance = Remittance::query()->firstOrFail();
    expect($remittance->lines)->toHaveCount(1)
        ->and($remittance->lines[0]->employee_id)->toBe($withNumber->id)
        ->and($remittance->lines[0]->member_number)->toBe($withNumber->sss)
        ->and($remittance->lines[0]->amount_centavos)->toBe(75_000);
});

it('copies the latest earlier month and leaves out people who have left', function () {
    [$stays, $leaves] = [cosWorker(), cosWorker()];
    $service = app(RemittanceService::class);
    $first = $service->generate(RemittanceKind::Sss, 2026, 8)['remittance'];
    $first->lines()->where('employee_id', $stays->id)->update(['amount_centavos' => 90_000]);
    $leaves->update(['employment_status' => EmploymentStatus::Resigned, 'last_day_of_service' => '2026-08-31']);

    $result = $service->generate(RemittanceKind::Sss, 2026, 10);

    expect($result['copied'])->toBe(1)->and($result['dropped'])->toBe(1)
        ->and($result['remittance']->lines)->toHaveCount(1)
        ->and($result['remittance']->lines[0]->amount_centavos)->toBe(90_000);
});

it('refuses a month that already exists', function () {
    cosWorker();
    $this->post('/remittances', ['kind' => 'sss', 'year' => 2026, 'month' => 10]);

    $this->post('/remittances', ['kind' => 'sss', 'year' => 2026, 'month' => 10])->assertSessionHas('flash');

    expect(Remittance::query()->count())->toBe(1);
});

it('adds up the totals in centavos for each fund', function () {
    $sss = Remittance::factory()->create(['kind' => RemittanceKind::Sss, 'month' => 10]);
    RemittanceLine::factory()->count(3)->create(['remittance_id' => $sss->id, 'amount_centavos' => 75_000]);

    $hdmf = Remittance::factory()->create(['kind' => RemittanceKind::Hdmf, 'month' => 10]);
    RemittanceLine::factory()->count(2)->create(['remittance_id' => $hdmf->id, 'amount_centavos' => 40_000, 'employer_share_centavos' => 10_000, 'compensation_centavos' => 500_000]);

    $cmpeai = Remittance::factory()->create(['kind' => RemittanceKind::Cmpeai, 'month' => 10]);
    RemittanceLine::factory()->create(['remittance_id' => $cmpeai->id, 'amount_centavos' => 26_918_00]);

    $service = app(RemittanceService::class);

    expect($service->totals($sss)['payable'])->toBe(225_000)
        ->and($service->totals($hdmf)['payable'])->toBe(100_000)
        ->and($service->totals($hdmf)['compensation'])->toBe(1_000_000)
        ->and($service->totals($cmpeai))->toMatchArray(['gross' => 2_691_800, 'serviceCharge' => 107_672, 'net' => 2_584_128, 'payable' => 2_584_128]);
});

it('saves the whole grid or nothing', function () {
    cosWorker();
    $remittance = app(RemittanceService::class)->generate(RemittanceKind::Sss, 2026, 10)['remittance']->load('lines');

    $rows = gridRows($remittance, fn (array $rows): array => [...$rows, ['last_name' => 'Newcomer', 'first_name' => 'Nina', 'member_number' => '33-1234567-8', 'amount' => '750']]);
    $this->put("/remittances/{$remittance->id}", ['grid' => json_encode($rows)])->assertRedirect("/remittances/{$remittance->id}");

    expect($remittance->lines()->count())->toBe(2)
        ->and($remittance->lines()->where('first_name', 'Nina')->value('amount_centavos'))->toBe(75_000);

    $broken = gridRows($remittance->fresh('lines'), fn (array $rows): array => [...$rows, ['last_name' => '', 'first_name' => 'No Surname', 'amount' => '750'], ['last_name' => 'Bad', 'first_name' => 'Amount', 'amount' => 'abc']]);
    $this->put("/remittances/{$remittance->id}", ['grid' => json_encode($broken)])->assertSessionHasErrors('form');

    expect($remittance->lines()->count())->toBe(2);
    expect(AuditLog::query()->where('collection_slug', 'remittances')->where('action', 'update')->exists())->toBeTrue();
});

it('locks a finalized month until it is reopened', function () {
    cosWorker();
    $remittance = app(RemittanceService::class)->generate(RemittanceKind::Sss, 2026, 10)['remittance']->load('lines');

    $this->post("/remittances/{$remittance->id}/finalize")->assertRedirect();
    expect($remittance->fresh()->isFinalized())->toBeTrue();

    $this->put("/remittances/{$remittance->id}", ['grid' => json_encode(gridRows($remittance))])->assertSessionHasErrors('form');
    $this->delete("/remittances/{$remittance->id}")->assertRedirect();
    expect(Remittance::query()->count())->toBe(1);

    $this->post("/remittances/{$remittance->id}/reopen");
    $this->put("/remittances/{$remittance->id}", ['grid' => json_encode(gridRows($remittance))])->assertSessionHasNoErrors();
});

it('will not finalize a month with nothing to pay', function () {
    $remittance = Remittance::factory()->create(['month' => 10]);

    $this->post("/remittances/{$remittance->id}/finalize");

    expect($remittance->fresh()->isFinalized())->toBeFalse();
});

it('warns about people with no member number', function () {
    $remittance = Remittance::factory()->create(['month' => 10]);
    RemittanceLine::factory()->create(['remittance_id' => $remittance->id, 'member_number' => null]);

    $this->get("/remittances/{$remittance->id}")->assertOk()->assertSee('no SSS number');
});

it('adds active COS workers who are not on the list', function () {
    cosWorker();
    $remittance = app(RemittanceService::class)->generate(RemittanceKind::Sss, 2026, 10)['remittance'];
    cosWorker();

    $this->post("/remittances/{$remittance->id}/missing");

    expect($remittance->lines()->count())->toBe(2);
});

it('exports the SSS layout with a real SUM and reads it back', function () {
    $remittance = Remittance::factory()->create(['kind' => RemittanceKind::Sss, 'year' => 2026, 'month' => 10]);
    RemittanceLine::factory()->create(['remittance_id' => $remittance->id, 'position' => 1, 'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'P', 'birth_date' => '1990-05-01', 'member_number' => '11-1111111-1', 'amount_centavos' => 75_000]);
    RemittanceLine::factory()->create(['remittance_id' => $remittance->id, 'position' => 2, 'last_name' => '=cmd', 'first_name' => 'Evil', 'middle_name' => null, 'member_number' => '22-2222222-2', 'amount_centavos' => 75_000]);

    $workbook = app(RemittancesWorkbook::class);
    $spreadsheet = $workbook->export($remittance);
    $sheet = $spreadsheet->getActiveSheet();

    expect($sheet->getTitle())->toBe('SSS OCTOBER 2026');
    $formulas = collect($sheet->getCellCollection()->getCoordinates())->filter(fn (string $cell): bool => $sheet->getCell($cell)->isFormula());
    expect($formulas)->toHaveCount(1)->and($sheet->getCell($formulas->first())->getValue())->toStartWith('=SUM(E');
    $cmd = collect($sheet->getCellCollection()->getCoordinates())->first(fn (string $cell): bool => str_contains(mb_strtolower((string) $sheet->getCell($cell)->getValue()), 'cmd'));
    expect($sheet->getCell($cmd)->isFormula())->toBeFalse();

    $path = tempnam(sys_get_temp_dir(), 'sss').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $plan = $workbook->analyze($path, RemittanceKind::Sss);

    expect($plan['errors'])->toBe([])
        ->and($plan['months'])->toHaveKey('2026-10')
        ->and($plan['months']['2026-10']['lines'])->toHaveCount(2)
        ->and($plan['months']['2026-10']['lines'][0])->toMatchArray(['last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'P', 'birth_date' => '1990-05-01', 'member_number' => '11-1111111-1', 'amount_centavos' => 75_000]);
});

it('round-trips the HDMF and CMPEAI layouts', function () {
    $workbook = app(RemittancesWorkbook::class);

    $hdmf = Remittance::factory()->create(['kind' => RemittanceKind::Hdmf, 'year' => 2026, 'month' => 9]);
    RemittanceLine::factory()->create(['remittance_id' => $hdmf->id, 'last_name' => 'Reyes', 'first_name' => 'Ana Marie', 'middle_name' => 'L', 'extension' => 'JR', 'member_number' => '1212-3434-5656', 'amount_centavos' => 40_000, 'employer_share_centavos' => 0, 'compensation_centavos' => 500_000]);
    $cmpeai = Remittance::factory()->create(['kind' => RemittanceKind::Cmpeai, 'year' => 2026, 'month' => 9]);
    RemittanceLine::factory()->create(['remittance_id' => $cmpeai->id, 'last_name' => 'Santos', 'first_name' => 'Maria', 'middle_name' => 'C', 'station' => 'Tagum', 'amount_centavos' => 121_700]);

    foreach ([[$hdmf, RemittanceKind::Hdmf], [$cmpeai, RemittanceKind::Cmpeai]] as [$remittance, $kind]) {
        $path = tempnam(sys_get_temp_dir(), 'rem').'.xlsx';
        (new Xlsx($workbook->export($remittance)))->save($path);
        $plan = $workbook->analyze($path, $kind);

        expect($plan['errors'])->toBe([])->and($plan['months'])->toHaveKey('2026-09');
        $line = $plan['months']['2026-09']['lines'][0];
        expect($line['last_name'])->toBe($remittance->lines[0]->last_name)
            ->and($line['amount_centavos'])->toBe($remittance->lines[0]->amount_centavos);
    }

    expect($plan['months']['2026-09']['lines'][0]['station'])->toBe('Tagum');
});

it('imports a workbook through the page and replaces an unfinalized month', function () {
    $worker = cosWorker(['last_name' => 'Villanueva', 'first_name' => 'Pedro', 'sss' => '44-4444444-4']);
    $remittance = Remittance::factory()->create(['kind' => RemittanceKind::Sss, 'year' => 2026, 'month' => 10]);
    RemittanceLine::factory()->create(['remittance_id' => $remittance->id, 'last_name' => 'Old', 'first_name' => 'Row']);
    RemittanceLine::factory()->create(['remittance_id' => $remittance->id, 'last_name' => 'Villanueva', 'first_name' => 'Pedro', 'middle_name' => null, 'member_number' => '44-4444444-4', 'amount_centavos' => 75_000, 'position' => 2]);

    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    (new Xlsx(app(RemittancesWorkbook::class)->export($remittance)))->save($path);
    $remittance->lines()->delete();
    RemittanceLine::factory()->create(['remittance_id' => $remittance->id, 'last_name' => 'Stale', 'first_name' => 'Entry']);

    $file = new UploadedFile($path, 'sss.xlsx', test: true);
    $response = $this->post('/remittances/import', ['kind' => 'sss', 'file' => $file])->assertOk()->assertSee('will be saved');
    preg_match('/name="token" value="([^"]+)"/', $response->getContent(), $token);

    $this->post('/remittances/import/commit', ['kind' => 'sss', 'token' => $token[1]])->assertRedirect();

    $names = $remittance->lines()->pluck('last_name')->all();
    expect($names)->toContain('Villanueva')->not->toContain('Stale')
        ->and($remittance->lines()->where('last_name', 'Villanueva')->value('employee_id'))->toBe($worker->id);
});

it('refuses to import over a finalized month', function () {
    $remittance = Remittance::factory()->create(['kind' => RemittanceKind::Sss, 'year' => 2026, 'month' => 10]);
    RemittanceLine::factory()->create(['remittance_id' => $remittance->id]);
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    (new Xlsx(app(RemittancesWorkbook::class)->export($remittance)))->save($path);
    $remittance->update(['status' => 'Finalized']);

    $this->post('/remittances/import', ['kind' => 'sss', 'file' => new UploadedFile($path, 'sss.xlsx', test: true)])->assertOk()->assertSee('is finalized');
});

it('asks for the month when a sheet does not say which one it is', function () {
    $remittance = Remittance::factory()->create(['kind' => RemittanceKind::Cmpeai, 'year' => 2026, 'month' => 10]);
    RemittanceLine::factory()->create(['remittance_id' => $remittance->id, 'station' => 'Tagum']);
    $spreadsheet = app(RemittancesWorkbook::class)->export($remittance);
    $spreadsheet->getActiveSheet()->setTitle('Sheet1');
    $spreadsheet->getActiveSheet()->getCell('A8')->setValue('');
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    $remittance->delete();

    $workbook = app(RemittancesWorkbook::class);
    expect($workbook->analyze($path, RemittanceKind::Cmpeai)['errors'])->not->toBe([])
        ->and($workbook->analyze($path, RemittanceKind::Cmpeai, 2026, 7)['months'])->toHaveKey('2026-07');
});

it('rejects a file that is not a workbook', function () {
    $file = UploadedFile::fake()->create('remit.xlsx', 5, 'application/octet-stream');

    $this->post('/remittances/import', ['kind' => 'sss', 'file' => $file])->assertOk()->assertSee('not a valid Excel workbook');
});

it('prints the voucher with the amount in words and the template wording', function () {
    $setting = RemittanceSetting::forKind(RemittanceKind::Cmpeai);
    $setting->update(['voucher_payee' => 'Sample Employees Association']);
    $remittance = Remittance::factory()->create(['kind' => RemittanceKind::Cmpeai, 'year' => 2026, 'month' => 10, 'voucher_number' => '2026-10-0042']);
    RemittanceLine::factory()->create(['remittance_id' => $remittance->id, 'amount_centavos' => 2_691_800]);

    $this->get("/remittances/{$remittance->id}/voucher")
        ->assertOk()
        ->assertSee('Twenty Five Thousand Eight Hundred Forty One Pesos and 28/100 Only')
        ->assertSee('Sample Employees Association')
        ->assertSee('2026-10-0042')
        ->assertSee('October 2026');
});

it('serves the voucher as a PDF', function () {
    $remittance = Remittance::factory()->create(['month' => 10]);
    RemittanceLine::factory()->create(['remittance_id' => $remittance->id]);

    $response = $this->get("/remittances/{$remittance->id}/voucher.pdf")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('saves the template and the voucher officials from the settings forms', function () {
    $this->put('/remittances/settings/cmpeai', ['title' => 'CMPEAI Remittance', 'default_amount' => '300.00', 'service_charge_percent' => '5', 'prepared_by_name' => 'Pat Prepared'])->assertRedirect();

    $setting = RemittanceSetting::forKind(RemittanceKind::Cmpeai);
    expect($setting->title)->toBe('CMPEAI Remittance')
        ->and($setting->service_charge_basis_points)->toBe(500)
        ->and($setting->default_amount_centavos)->toBe(30_000);

    $this->put('/remittances/settings/voucher', ['kind' => 'cmpeai', 'mode_of_payment' => 'ADA', 'approver_name' => 'Ada Approver'])->assertRedirect();
    expect(VoucherSetting::current()->approver_name)->toBe('Ada Approver');

    $this->put('/remittances/settings/cmpeai', ['title' => 'x', 'default_amount' => 'oops'])->assertSessionHasErrors('form');
    $this->put('/remittances/settings/cmpeai', ['title' => 'x', 'default_amount' => '1', 'service_charge_percent' => '90'])->assertSessionHasErrors('form');
});

it('shows each page for each fund', function (RemittanceKind $kind) {
    $remittance = Remittance::factory()->create(['kind' => $kind, 'month' => 10]);
    RemittanceLine::factory()->create(['remittance_id' => $remittance->id]);

    $this->get('/remittances?kind='.$kind->value)->assertOk()->assertSee('October 2026');
    $this->get("/remittances/{$remittance->id}")->assertOk()->assertSee($kind->amountLabel());
    $this->get("/remittances/{$remittance->id}/print")->assertOk();
    $this->get("/remittances/{$remittance->id}/export")->assertOk();
    $this->get('/remittances/settings?kind='.$kind->value)->assertOk();
})->with(RemittanceKind::cases());

it('keeps remittances away from signed-out visitors', function () {
    auth()->logout();

    $this->get('/remittances')->assertRedirectContains('/login');
});
