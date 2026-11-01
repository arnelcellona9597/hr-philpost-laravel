<?php

use App\Enums\Classification;
use App\Enums\ContractStatus;
use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Exceptions\UserFacingException;
use App\Models\Contract;
use App\Models\ContractSetting;
use App\Models\ContractTemplate;
use App\Models\Employee;
use App\Models\User;
use App\Services\ContractService;
use App\Services\ContractWordExporter;
use App\Support\CertificateBlocks;
use App\Support\ContractBlocks;
use App\Support\DefaultContractTemplate;

function cosEmployee(array $attributes = []): Employee
{
    return Employee::factory()->create([
        'classification' => Classification::Cos,
        'employment_status' => EmploymentStatus::Active,
        'date_hired' => '2026-01-05',
        'position' => 'Mail Handler',
        'address' => '12 Sample St., Sample City',
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function contractRow(Employee $employee, array $overrides = []): array
{
    return [
        'employee' => $employee->id,
        'position' => 'Mail Handler',
        'office' => 'Sample Area Office',
        'place_of_service' => 'Sample City',
        'address' => '12 Sample St., Sample City',
        'term_start' => '2026-07-01',
        'term_end' => '2026-12-31',
        'daily_wage' => '450',
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->hr = User::factory()->create(['name' => 'Rita Santos']);
    signIn($this->hr);
    $this->travelTo('2026-06-20 10:00:00');
    $this->template = ContractTemplate::query()->firstOrFail();
    ContractSetting::current()->update(['area_director_name' => 'Ana R. Reyes', 'adfin_chief_name' => 'Ben T. Santos', 'hrm_chief_name' => 'Carla M. Lim', 'budget_officer_name' => 'Dan P. Cruz', 'wage_order' => 'Wage Order No. 1']);
});

it('installs the standard contract with placeholders only', function () {
    expect($this->template->key)->toBe('contract-of-service')
        ->and(CertificateBlocks::unknownPlaceholders(DefaultContractTemplate::blocks(), ContractBlocks::fields()))->toBe([]);

    $json = json_encode($this->template->blocks);
    expect($json)->toContain('{{fullNameUpper}}')->toContain('{{remuneration}}')->toContain('{{areaDirectorInitials}}');
});

it('issues a contract with the person, the wages and the officials merged in and frozen', function () {
    $employee = cosEmployee(['first_name' => 'Maylin', 'last_name' => 'Sample', 'gender' => Gender::Female]);

    $contract = app(ContractService::class)->issue($this->hr, $this->template->id, $employee->id, contractRow($employee, ['second_wage' => '470', 'second_wage_from' => '2026-10-01']), '2026-06-20');

    expect($contract->control_number)->toBe('COS-2026-0001')
        ->and($contract->status)->toBe(ContractStatus::Valid)
        ->and($contract->daily_wage_centavos)->toBe(45_000)
        ->and($contract->second_wage_centavos)->toBe(47_000)
        ->and($contract->premium_percent)->toBe(15);

    $text = json_encode($contract->content['blocks'], JSON_UNESCAPED_UNICODE);
    expect($text)->toContain('MAYLIN')->toContain('Four Hundred Fifty Pesos (₱450.00) per day')->toContain('Four Hundred Seventy Pesos (₱470.00) per day starting October 1, 2026')
        ->toContain('Wage Order No. 1')->toContain('premium of 15%')->toContain('Ana R. Reyes')->toContain('Initials: AR  BS  CL  DC')
        ->not->toContain('{{');

    ContractSetting::current()->update(['area_director_name' => 'Someone Else']);
    ContractTemplate::query()->update(['blocks' => []]);
    expect(json_encode($contract->fresh()->content['blocks'], JSON_UNESCAPED_UNICODE))->toContain('Ana R. Reyes')->not->toContain('Someone Else');
});

it('numbers contracts in order and never reuses a number', function () {
    $service = app(ContractService::class);
    [$first, $second] = [cosEmployee(), cosEmployee()];

    $a = $service->issue($this->hr, $this->template->id, $first->id, contractRow($first), '2026-06-20');
    $b = $service->issue($this->hr, $this->template->id, $second->id, contractRow($second), '2026-06-20');
    $a->delete();
    $third = cosEmployee();
    $c = $service->issue($this->hr, $this->template->id, $third->id, contractRow($third), '2026-06-20');

    expect([$a->control_number, $b->control_number, $c->control_number])->toBe(['COS-2026-0001', 'COS-2026-0002', 'COS-2026-0003']);
});

it('issues a whole group together or none at all', function () {
    $service = app(ContractService::class);
    $people = [cosEmployee(), cosEmployee(), cosEmployee()];

    $issued = $service->issueMany($this->hr, $this->template->id, array_map(fn (Employee $employee): array => contractRow($employee), $people), '2026-06-20');
    expect($issued)->toHaveCount(3)->and(Contract::query()->count())->toBe(3);

    $others = [cosEmployee(), cosEmployee()];
    $rows = [contractRow($others[0]), contractRow($others[1], ['daily_wage' => 'abc'])];

    expect(fn () => $service->issueMany($this->hr, $this->template->id, $rows, '2026-06-20'))->toThrow(UserFacingException::class, 'wage');
    expect(Contract::query()->count())->toBe(3);
});

it('refuses overlapping valid contracts for the same worker', function () {
    $service = app(ContractService::class);
    $employee = cosEmployee();
    $service->issue($this->hr, $this->template->id, $employee->id, contractRow($employee), '2026-06-20');

    expect(fn () => $service->issue($this->hr, $this->template->id, $employee->id, contractRow($employee, ['term_start' => '2026-12-01', 'term_end' => '2027-05-31']), '2026-06-20'))
        ->toThrow(UserFacingException::class, 'already has contract COS-2026-0001');

    $service->issue($this->hr, $this->template->id, $employee->id, contractRow($employee, ['term_start' => '2027-01-01', 'term_end' => '2027-06-30']), '2026-06-20');
    expect(Contract::query()->count())->toBe(2);

    $twice = cosEmployee();
    expect(fn () => $service->issueMany($this->hr, $this->template->id, [contractRow($twice), contractRow($twice, ['term_start' => '2026-09-01'])], '2026-06-20'))
        ->toThrow(UserFacingException::class, 'listed twice');
});

it('lets a voided contract be replaced for the same dates', function () {
    $service = app(ContractService::class);
    $employee = cosEmployee();
    $contract = $service->issue($this->hr, $this->template->id, $employee->id, contractRow($employee), '2026-06-20');

    $service->void($contract, 'wrong wage');
    $service->issue($this->hr, $this->template->id, $employee->id, contractRow($employee, ['daily_wage' => '460']), '2026-06-20');

    expect($contract->fresh()->isVoid())->toBeTrue()->and(Contract::query()->where('status', 'Valid')->count())->toBe(1);
    expect(fn () => $service->void($contract->fresh(), 'again'))->toThrow(UserFacingException::class, 'already void');
});

it('validates the terms of each row', function (array $change, string $message) {
    $employee = cosEmployee();

    expect(fn () => app(ContractService::class)->issue($this->hr, $this->template->id, $employee->id, contractRow($employee, $change), '2026-06-20'))
        ->toThrow(UserFacingException::class, $message);
    expect(Contract::query()->count())->toBe(0);
})->with([
    'no dates' => [['term_start' => ''], 'start and end date'],
    'end before start' => [['term_end' => '2026-06-01'], 'ends before it starts'],
    'no position' => [['position' => ''], 'needs a position'],
    'no wage' => [['daily_wage' => ''], 'needs a daily wage'],
    'second wage without date' => [['second_wage' => '470'], 'no valid date'],
    'premium too high' => [['premium_percent' => 150], 'premium'],
]);

it('keeps issued contracts unchangeable apart from voiding', function () {
    $employee = cosEmployee();
    $contract = app(ContractService::class)->issue($this->hr, $this->template->id, $employee->id, contractRow($employee), '2026-06-20');

    expect(fn () => $contract->forceFill(['position' => 'Changed'])->save())->toThrow(UserFacingException::class, 'cannot be changed');
});

it('issues from the page, one contract or a group', function () {
    $people = [cosEmployee(), cosEmployee()];
    $grid = array_map(fn (Employee $employee): array => ['selected' => true, ...contractRow($employee)], $people);
    $grid[] = ['selected' => false, ...contractRow(cosEmployee())];

    $this->post('/contracts', ['template' => $this->template->id, 'issued_date' => '2026-06-20', 'term_start' => '2026-07-01', 'term_end' => '2026-12-31', 'grid' => json_encode($grid)])
        ->assertRedirect('/contracts');

    expect(Contract::query()->count())->toBe(2);

    $solo = cosEmployee();
    $this->post('/contracts', ['template' => $this->template->id, 'issued_date' => '2026-06-20', 'term_start' => '2026-07-01', 'term_end' => '2026-12-31', 'grid' => json_encode([['selected' => true, ...contractRow($solo)]])])
        ->assertRedirect('/contracts/'.Contract::query()->latest('id')->value('id'));

    $this->post('/contracts', ['template' => $this->template->id, 'term_start' => '2026-07-01', 'term_end' => '2026-12-31', 'grid' => json_encode([])])->assertSessionHasErrors('form');
});

it('lists only active COS workers without an overlapping contract', function () {
    $free = cosEmployee(['last_name' => 'Freeworker', 'first_name' => 'Fay', 'middle_name' => null]);
    $has = cosEmployee(['last_name' => 'Hascontract', 'first_name' => 'Hal', 'middle_name' => null]);
    cosEmployee(['last_name' => 'Regularworker', 'first_name' => 'Rey', 'middle_name' => null, 'classification' => Classification::Regular]);
    cosEmployee(['last_name' => 'Goneworker', 'first_name' => 'Gil', 'middle_name' => null, 'employment_status' => EmploymentStatus::Resigned, 'last_day_of_service' => '2026-05-31']);
    app(ContractService::class)->issue($this->hr, $this->template->id, $has->id, contractRow($has), '2026-06-20');

    $names = collect(app(ContractService::class)->candidates('2026-08-01', '2026-08-31'))->pluck('full_name')->all();

    expect($names)->toBe(['Freeworker, Fay']);
    $this->get('/contracts/new?term_start=2026-08-01&term_end=2026-08-31')->assertOk()->assertSee('Freeworker, Fay')->assertDontSee('Hascontract')->assertDontSee('Regularworker');
    $this->get("/contracts/new?employee={$has->id}")->assertOk()->assertSee('Hascontract');
});

it('builds a Word file that opens and carries the contract text', function () {
    $employee = cosEmployee(['first_name' => 'Maylin', 'last_name' => 'Sample', 'gender' => Gender::Female]);
    $contract = app(ContractService::class)->issue($this->hr, $this->template->id, $employee->id, contractRow($employee), '2026-06-20');

    $bytes = app(ContractWordExporter::class)->render($contract);
    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;

    expect($zip->open($path))->toBeTrue()
        ->and($zip->locateName('word/document.xml'))->not->toBeFalse()
        ->and($zip->locateName('word/footer1.xml'))->not->toBeFalse();

    $document = $zip->getFromName('word/document.xml');
    $footer = $zip->getFromName('word/footer1.xml');
    $zip->close();

    expect($document)->toContain('CONTRACT OF SERVICE')->toContain('MAYLIN')->toContain('Four Hundred Fifty Pesos')->toContain('ACKNOWLEDGEMENT')->toContain('w:br w:type="page"')->not->toContain('{{')
        ->and($footer)->toContain('Initials: AR  BS  CL  DC');

    $response = $this->get("/contracts/{$contract->id}/docx")->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('COS-2026-0001')->toContain('.docx')
        ->and(substr($response->getContent(), 0, 2))->toBe('PK');
});

it('escapes markup in names when building Word and PDF files', function () {
    $employee = cosEmployee(['last_name' => 'O<b>Brien & "Sons"', 'first_name' => 'Pat']);
    $contract = app(ContractService::class)->issue($this->hr, $this->template->id, $employee->id, contractRow($employee), '2026-06-20');

    $bytes = app(ContractWordExporter::class)->render($contract);
    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    expect($xml)->toContain('O&lt;B&gt;BRIEN &amp;')->not->toContain('<B>BRIEN');
    $this->get("/contracts/{$contract->id}/pdf")->assertOk();
    $this->get("/contracts/{$contract->id}")->assertOk()->assertDontSee('<b>Brien', false);
});

it('serves the page, the print view and the PDF', function () {
    $employee = cosEmployee();
    $contract = app(ContractService::class)->issue($this->hr, $this->template->id, $employee->id, contractRow($employee), '2026-06-20');

    $this->get("/contracts/{$contract->id}")->assertOk()->assertSee('COS-2026-0001')->assertSee('CONTRACT OF SERVICE');
    $this->get("/contracts/{$contract->id}/print")->assertOk()->assertSee('cert-pagebreak', false);
    $pdf = $this->get("/contracts/{$contract->id}/pdf")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
});

it('downloads chosen contracts as a ZIP of Word or PDF files', function (string $format, string $extension) {
    $people = [cosEmployee(), cosEmployee()];
    $issued = app(ContractService::class)->issueMany($this->hr, $this->template->id, array_map(fn (Employee $employee): array => contractRow($employee), $people), '2026-06-20');

    $response = $this->post('/contracts/download', ['ids' => array_map(fn (Contract $contract): int => $contract->id, $issued), 'format' => $format])->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    $zip->open($path);

    expect($zip->numFiles)->toBe(2)->and($zip->getNameIndex(0))->toEndWith($extension);
    $this->post('/contracts/download', ['ids' => [], 'format' => $format])->assertRedirect('/contracts');
})->with([['docx', '.docx'], ['pdf', '.pdf']]);

it('voids a contract and lets only a system admin delete it', function () {
    $employee = cosEmployee();
    $contract = app(ContractService::class)->issue($this->hr, $this->template->id, $employee->id, contractRow($employee), '2026-06-20');

    $this->post("/contracts/{$contract->id}/void", ['reason' => ''])->assertSessionHasErrors('form');
    $this->post("/contracts/{$contract->id}/void", ['reason' => 'reissued'])->assertRedirect("/contracts/{$contract->id}");
    expect($contract->fresh()->isVoid())->toBeTrue();

    $this->delete("/contracts/{$contract->id}")->assertForbidden();
    signIn(User::factory()->systemAdmin()->create());
    $this->delete("/contracts/{$contract->id}")->assertRedirect('/contracts');
    expect(Contract::query()->count())->toBe(0);
});

it('stops the worker from being deleted while contracts exist', function () {
    $employee = cosEmployee();
    app(ContractService::class)->issue($this->hr, $this->template->id, $employee->id, contractRow($employee), '2026-06-20');

    expect($employee->deleteBlocker())->not->toBeNull();
});

it('shows contracts on the worker profile and counts those ending soon', function () {
    $employee = cosEmployee();
    app(ContractService::class)->issue($this->hr, $this->template->id, $employee->id, contractRow($employee, ['term_start' => '2026-06-01', 'term_end' => '2026-07-10']), '2026-06-20');

    $this->get("/employees/{$employee->id}")->assertOk()->assertSee('Contracts of service')->assertSee('COS-2026-0001');
    $this->get('/contracts')->assertOk()->assertSee('ends within 30 days', false)->assertSee('ends soon');
    $this->get('/contracts?ending=1')->assertOk()->assertSee('COS-2026-0001');
    $this->get('/contracts?q=nobody-here')->assertOk()->assertSee('No contracts match');
});

it('edits templates with the shared editor, including the new blocks', function () {
    $this->get("/contracts/templates/{$this->template->id}")->assertOk()->assertSee('Page footer')->assertSee('Page break')->assertSee('areaDirectorInitials');

    $blocks = [...$this->template->blocks, ['id' => 'zz1', 'type' => 'pageBreak'], ['id' => 'zz2', 'type' => 'pageFooter', 'text' => 'Page of {{controlNumber}}', 'align' => 'right']];
    $this->put("/contracts/templates/{$this->template->id}", ['name' => 'Contract of Service', 'prefix' => 'cos', 'blocks' => json_encode($blocks), 'paper_size' => 'Legal', 'font' => 'sans', 'margin' => 'narrow', 'active' => '1'])->assertSessionHasNoErrors();

    $saved = $this->template->fresh();
    expect($saved->paper_size)->toBe('Legal')->and(collect($saved->blocks)->pluck('type'))->toContain('pageBreak', 'pageFooter');

    $bad = [...$blocks, ['id' => 'zz3', 'type' => 'paragraph', 'text' => 'Hello {{purpose}}', 'align' => 'left', 'size' => 'md', 'indent' => false]];
    $this->put("/contracts/templates/{$this->template->id}", ['name' => 'Contract of Service', 'prefix' => 'COS', 'blocks' => json_encode($bad)])->assertSessionHasErrors('form');
});

it('creates, duplicates, toggles and protects templates', function () {
    $this->post('/contracts/templates', ['name' => 'Contract – Driver'])->assertRedirect();
    $driver = ContractTemplate::query()->where('name', 'Contract – Driver')->firstOrFail();
    expect(collect($driver->blocks)->pluck('type')->all())->toBe(collect(DefaultContractTemplate::blocks())->pluck('type')->all());

    $this->post('/contracts/templates', ['name' => 'Contract – Driver'])->assertSessionHasErrors('form');
    $this->post("/contracts/templates/{$driver->id}/duplicate")->assertRedirect();
    expect(ContractTemplate::query()->where('name', 'Contract – Driver (copy)')->exists())->toBeTrue();

    $this->post("/contracts/templates/{$driver->id}/toggle");
    expect($driver->fresh()->active)->toBeFalse();
    expect(fn () => app(ContractService::class)->issue($this->hr, $driver->id, cosEmployee()->id, contractRow(cosEmployee()), '2026-06-20'))->toThrow(UserFacingException::class, 'inactive');

    $employee = cosEmployee();
    app(ContractService::class)->issue($this->hr, $this->template->id, $employee->id, contractRow($employee), '2026-06-20');
    $this->delete("/contracts/templates/{$this->template->id}")->assertRedirect('/contracts/templates');
    expect(ContractTemplate::query()->whereKey($this->template->id)->exists())->toBeTrue();
    $this->delete("/contracts/templates/{$driver->id}");
    expect(ContractTemplate::query()->whereKey($driver->id)->exists())->toBeFalse();
});

it('saves the officials and wage wording', function () {
    $this->put('/contracts/settings', ['area_director_name' => 'New Director', 'area_director_title' => 'Area Director', 'premium_percent' => '10', 'wage_order' => 'Order 7', 'default_place_of_service' => 'Somewhere'])->assertRedirect('/contracts/settings');

    expect(ContractSetting::current())->area_director_name->toBe('New Director')->premium_percent->toBe(10)->default_place_of_service->toBe('Somewhere');
    $this->put('/contracts/settings', ['premium_percent' => '500'])->assertSessionHasErrors('form');
    $this->get('/contracts/settings')->assertOk()->assertSee('New Director');
});

it('keeps contracts away from signed-out visitors', function () {
    auth()->logout();

    $this->get('/contracts')->assertRedirectContains('/login');
});

it('uses the chosen font in the Word file', function () {
    $this->template->update(['font' => 'georgia']);
    $employee = cosEmployee();
    $contract = app(ContractService::class)->issue($this->hr, $this->template->id, $employee->id, contractRow($employee), '2026-06-20');

    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, app(ContractWordExporter::class)->render($contract));
    $zip = new ZipArchive;
    $zip->open($path);
    $styles = $zip->getFromName('word/styles.xml');
    $zip->close();

    expect($styles)->toContain('Georgia');
});
