<?php

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Enums\RateType;
use App\Enums\Role;
use App\Exceptions\UserFacingException;
use App\Models\Branch;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\ContractSetting;
use App\Models\DocumentSequence;
use App\Models\Employee;
use App\Models\Media;
use App\Models\PayChange;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\PdfRenderer;
use App\Support\CertificateRenderer;
use Illuminate\Support\Facades\Storage;

function certificateTemplate(string $key = 'coe-active'): CertificateTemplate
{
    return CertificateTemplate::query()->where('key', $key)->firstOrFail();
}

function certificateEmployee(array $attributes = []): Employee
{
    return Employee::factory()->create([
        'first_name' => 'Maria',
        'middle_name' => 'Santos',
        'last_name' => 'Reyes',
        'gender' => Gender::Female,
        'position' => 'Clerk II',
        'station_id' => Branch::factory()->create(['name' => 'Main Office'])->id,
        'date_hired' => '2018-03-05',
        ...$attributes,
    ]);
}

function certificateText(Certificate $certificate): string
{
    return collect($certificate->content['blocks'])->map(fn (array $block): string => $block['text'] ?? '')->implode(' ');
}

beforeEach(function () {
    $this->hr = User::factory()->create(['role' => Role::HrStaff]);
    signIn($this->hr);
});

it('starts with the three built-in templates', function () {
    expect(CertificateTemplate::query()->orderBy('sort_order')->pluck('key')->all())->toBe(['coe-active', 'coe-newly-hired', 'coe-separated']);
});

it('numbers certificates per prefix and year and freezes the merged text', function () {
    $employee = certificateEmployee();

    $this->post('/certificates', ['employee' => $employee->id, 'template' => certificateTemplate()->id, 'purpose' => 'housing loan', 'issued_date' => '2026-10-04'])
        ->assertRedirect();

    $certificate = Certificate::query()->sole();

    expect($certificate->control_number)->toBe('COE-2026-0001')
        ->and($certificate->employee_name)->toBe('Maria S. Reyes')
        ->and($certificate->issued_by)->toBe($this->hr->id)
        ->and(certificateText($certificate))->toContain('**MS. MARIA S. REYES**')
        ->toContain('housing loan')
        ->toContain('Issued this 4th day of October, 2026 at Davao City, Philippines.')
        ->not->toContain('{{');

    $next = app(CertificateService::class)->issue($this->hr, certificateTemplate()->id, $employee->id, null, '2027-01-02');
    $sameYear = app(CertificateService::class)->issue($this->hr, certificateTemplate()->id, $employee->id, null, '2026-11-01');

    expect($next->control_number)->toBe('COE-2027-0001')
        ->and($sameYear->control_number)->toBe('COE-2026-0002')
        ->and(certificateText($next))->toContain('whatever legal purpose it may serve');
});

it('never reuses a number after the highest certificate is deleted', function () {
    $employee = certificateEmployee();
    $first = app(CertificateService::class)->issue($this->hr, certificateTemplate()->id, $employee->id, null, '2026-10-04');
    $first->delete();

    expect(app(CertificateService::class)->issue($this->hr, certificateTemplate()->id, $employee->id, null, '2026-10-05')->control_number)->toBe('COE-2026-0002')
        ->and(DocumentSequence::query()->where('scope', 'certificate:COE:2026')->value('last_number'))->toBe(2);
});

it('writes the period of engagement for former employees', function () {
    $employee = certificateEmployee(['employment_status' => EmploymentStatus::Resigned, 'last_day_of_service' => '2026-09-05']);

    $certificate = app(CertificateService::class)->issue($this->hr, certificateTemplate('coe-separated')->id, $employee->id, null, '2026-10-04');

    expect(certificateText($certificate))->toContain('was engaged as a')->toContain('to September 5, 2026**.');
});

it('ignores computed fields sent by the client', function () {
    $this->post('/certificates', [
        'employee' => certificateEmployee()->id,
        'template' => certificateTemplate()->id,
        'issued_date' => '2026-10-04',
        'control_number' => 'HACK-1',
        'status' => 'Void',
        'content' => ['blocks' => []],
        'issued_by' => 999,
    ]);

    $certificate = Certificate::query()->sole();

    expect($certificate->control_number)->toBe('COE-2026-0001')
        ->and($certificate->isVoid())->toBeFalse()
        ->and($certificate->issued_by)->toBe($this->hr->id);
});

it('keeps issued certificates unchanged when the template or employee changes later', function () {
    $employee = certificateEmployee();
    $template = certificateTemplate();
    $certificate = app(CertificateService::class)->issue($this->hr, $template->id, $employee->id, null, '2026-10-04');
    $before = $certificate->fresh()->content;

    $template->update(['blocks' => [['id' => 'a', 'type' => 'paragraph', 'text' => 'Changed', 'align' => 'left', 'size' => 'md', 'indent' => false]], 'paper_size' => 'Legal']);
    $employee->update(['position' => 'Director']);

    expect($certificate->fresh()->content)->toEqual($before)
        ->and(certificateText($certificate->fresh()))->toContain('Clerk II');
});

it('refuses inactive templates and missing choices', function () {
    $template = certificateTemplate();
    $template->update(['active' => false]);
    $employee = certificateEmployee();

    $this->from('/certificates/new')->post('/certificates', ['employee' => $employee->id, 'template' => $template->id])
        ->assertRedirect('/certificates/new')
        ->assertSessionHasErrors(['form' => 'This certificate template is inactive. Activate it or choose another one.']);

    $this->post('/certificates', ['template' => $template->id])->assertSessionHasErrors(['form' => 'Choose an employee.']);
    $this->post('/certificates', ['employee' => $employee->id])->assertSessionHasErrors(['form' => 'Choose a certificate.']);

    expect(Certificate::query()->count())->toBe(0);
});

it('voids only with a reason and never goes back', function () {
    $certificate = app(CertificateService::class)->issue($this->hr, certificateTemplate()->id, certificateEmployee()->id, null, '2026-10-04');

    $this->post("/certificates/{$certificate->id}/void", ['reason' => ' '])->assertSessionHasErrors(['form' => 'Give a reason for voiding the certificate.']);
    expect($certificate->fresh()->isVoid())->toBeFalse();

    $this->post("/certificates/{$certificate->id}/void", ['reason' => 'wrong purpose'])->assertSessionHasNoErrors();
    $void = $certificate->fresh();

    expect($void->isVoid())->toBeTrue()
        ->and($void->void_reason)->toBe('wrong purpose')
        ->and($void->voided_at)->not->toBeNull();

    expect(fn () => $void->forceFill(['status' => 'Valid'])->save())->toThrow(UserFacingException::class, 'A voided certificate cannot be made valid again.');
});

it('refuses any change to an issued certificate except voiding', function () {
    $certificate = app(CertificateService::class)->issue($this->hr, certificateTemplate()->id, certificateEmployee()->id, null, '2026-10-04');

    expect(fn () => $certificate->forceFill(['purpose' => 'something else'])->save())->toThrow(UserFacingException::class);
});

it('lets only System Admins delete a certificate', function () {
    $certificate = app(CertificateService::class)->issue($this->hr, certificateTemplate()->id, certificateEmployee()->id, null, '2026-10-04');

    $this->delete("/certificates/{$certificate->id}")->assertForbidden();
    signIn(User::factory()->create(['role' => Role::SystemAdmin]));
    $this->delete("/certificates/{$certificate->id}")->assertRedirect('/certificates');

    expect(Certificate::query()->count())->toBe(0);
});

it('shows the list, the certificate, the print page and the preview with values escaped', function () {
    $employee = certificateEmployee(['first_name' => '<script>x</script>']);
    $certificate = app(CertificateService::class)->issue($this->hr, certificateTemplate()->id, $employee->id, '<b>loan</b>', '2026-10-04');

    $this->get('/certificates?q=COE-2026')->assertOk()->assertSee('COE-2026-0001');
    $this->get('/certificates?status=Void')->assertOk()->assertDontSee('COE-2026-0001');
    $this->get("/certificates/{$certificate->id}")->assertOk()->assertDontSee('<script>x', false)->assertSee('&lt;b&gt;loan&lt;/b&gt;', false);
    $this->get("/certificates/{$certificate->id}/print")->assertOk()->assertSee('cert-paper', false);

    $this->get("/certificates/preview?employee={$employee->id}&template=".certificateTemplate()->id.'&purpose=%3Ci%3E&issued_date=2026-10-04')
        ->assertOk()
        ->assertHeader('X-Paper', 'A4')
        ->assertHeader('X-Margin', 'normal')
        ->assertSee('COE-2026-????')
        ->assertDontSee('<i>', false);

    $this->get('/certificates/preview')->assertNoContent();
    $this->get('/certificates/new')->assertOk()->assertSee('Issue a certificate');
});

it('renders serif and sans PDFs, with a VOID mark and characters outside Latin-1', function (string $font) {
    $template = certificateTemplate();
    $template->update(['font' => $font]);
    $employee = certificateEmployee(['first_name' => 'Ñora', 'last_name' => 'Dela Cruz – Żak']);
    $certificate = app(CertificateService::class)->issue($this->hr, $template->id, $employee->id, null, '2026-10-04');

    $pdf = app(PdfRenderer::class)->certificate($certificate);
    expect($pdf)->toStartWith('%PDF-');

    app(CertificateService::class)->void($certificate, 'test');
    expect(app(PdfRenderer::class)->certificate($certificate->fresh()))->toStartWith('%PDF-');

    $response = $this->get("/certificates/{$certificate->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('COE-2026-');
})->with(['serif', 'sans']);

it('gives unique numbers across a run of issues', function () {
    $employee = certificateEmployee();
    $numbers = collect(range(1, 5))->map(fn (): string => app(CertificateService::class)->issue($this->hr, certificateTemplate()->id, $employee->id, null, '2026-10-04')->control_number);

    expect($numbers->unique())->toHaveCount(5);
});

it('writes the wage sentence from the Compensation History, like the office samples', function () {
    $employee = certificateEmployee(['classification' => Classification::Cos, 'position' => 'Sorter/Encoder', 'date_hired' => '2026-01-05']);
    PayChange::factory()->create(['employee_id' => $employee->id, 'effective_on' => '2026-01-05', 'amount_centavos' => 52_500, 'premium_percent' => 15]);

    $text = certificateText(app(CertificateService::class)->issue($this->hr, certificateTemplate('coe-active')->id, $employee->id, null, '2026-10-04'));

    expect($text)
        ->toContain('has been hired as a Contract of Service (COS) worker of the Philippine Postal Corporation, Area 7, Eastern Mindanao, and currently assigned as Sorter/Encoder')
        ->toContain('effective **January 5, 2026 to present** with a daily wage rate of Five Hundred Twenty-Five Pesos (Php525.00) plus a premium equivalent of fifteen percent (15%) to her daily wage rate.')
        ->toContain('Her contract is renewable every six (6) months based on her performance.')
        ->toContain('for **whatever legal purpose it may serve**.');
});

it('leaves the wage out when there is no rate, and calls a contractor a Service Contractor', function () {
    $employee = certificateEmployee(['classification' => Classification::Contractual, 'date_hired' => '2026-01-05']);

    $text = certificateText(app(CertificateService::class)->issue($this->hr, certificateTemplate('coe-newly-hired')->id, $employee->id, null, '2026-10-04'));

    expect($text)->toContain('hired as a Service Contractor')->toContain('effective **January 5, 2026**. Her contract')->not->toContain('wage rate');
});

it('uses a monthly salary sentence for monthly-paid staff', function () {
    $employee = certificateEmployee();
    PayChange::factory()->create(['employee_id' => $employee->id, 'effective_on' => '2020-01-01', 'rate_type' => RateType::Monthly, 'amount_centavos' => 2_500_000]);

    expect(certificateText(app(CertificateService::class)->issue($this->hr, certificateTemplate('coe-active')->id, $employee->id, null, '2026-10-04')))
        ->toContain('with a monthly salary of Twenty-Five Thousand Pesos (Php25,000.00).');
});

it('uses the wage in force on the date issued', function () {
    $employee = certificateEmployee();
    PayChange::factory()->create(['employee_id' => $employee->id, 'effective_on' => '2026-01-01', 'amount_centavos' => 51_000, 'premium_percent' => 2]);
    PayChange::factory()->create(['employee_id' => $employee->id, 'effective_on' => '2026-11-01', 'amount_centavos' => 54_000, 'premium_percent' => 15]);

    expect(certificateText(app(CertificateService::class)->issue($this->hr, certificateTemplate('coe-active')->id, $employee->id, null, '2026-10-04')))
        ->toContain('Five Hundred Ten Pesos (Php510.00) plus a premium equivalent of two percent (2%)');
});

it('signs with the HRM Chief from the settings and writes a proper-case last name', function () {
    ContractSetting::current()->update(['hrm_chief_name' => 'Sample T. Chief', 'hrm_chief_title' => 'Acting Chief, HRM Section']);
    $employee = certificateEmployee(['last_name' => 'REYES']);

    $certificate = app(CertificateService::class)->issue($this->hr, certificateTemplate('coe-active')->id, $employee->id, null, '2026-10-04');

    expect(json_encode($certificate->content['blocks']))->toContain('Sample T. Chief')->toContain('Acting Chief, HRM Section')
        ->and(certificateText($certificate))->toContain('request of Ms. Reyes for');
});

it('freezes the office letterhead on an issued certificate and shows it in the PDF', function () {
    SiteSetting::current()->update(['letterhead_name' => "SAMPLE POSTAL CORPORATION\nSAMPLE AREA", 'letterhead_details' => '1 Sample Avenue', 'letterhead_section' => 'Human Resource Management Section']);
    $certificate = app(CertificateService::class)->issue($this->hr, certificateTemplate()->id, certificateEmployee()->id, null, '2026-10-04');

    $this->get("/certificates/{$certificate->id}")->assertOk()->assertSee('SAMPLE AREA')->assertSee('1 Sample Avenue')->assertSee('Human Resource Management Section');

    SiteSetting::current()->update(['letterhead_name' => 'CHANGED LATER']);

    $this->get("/certificates/{$certificate->id}")->assertSee('SAMPLE AREA')->assertDontSee('CHANGED LATER');
    expect(substr($this->get("/certificates/{$certificate->id}/pdf")->assertOk()->getContent(), 0, 4))->toBe('%PDF');
});

it('offers every template when issuing, and templates can be edited', function () {
    $this->get('/certificates/new')->assertOk()->assertSee('COE – Current Worker')->assertSee('COE – Newly Hired')->assertSee('COE – Resigned / Separated');

    $template = certificateTemplate();
    $page = $this->get("/certificates/templates/{$template->id}")->assertOk()->assertSee('Office letterhead')->assertSee('compensationClause');
    $blocks = $page->viewData('editor')['blocks'];
    $blocks[5]['text'] = 'Edited wording for {{fullName}}.';

    $this->put("/certificates/templates/{$template->id}", ['name' => $template->name, 'prefix' => 'COE', 'blocks' => json_encode($blocks), 'font' => 'sans', 'active' => '1'])->assertSessionHasNoErrors();

    expect(certificateText(app(CertificateService::class)->issue($this->hr, $template->id, certificateEmployee()->id, null, '2026-10-04')))->toContain('Edited wording for Maria S. Reyes.');
});

it('fills in the office letterhead wording only where it is blank', function () {
    $settings = SiteSetting::current();
    $settings->update(['letterhead_name' => null, 'letterhead_details' => '', 'letterhead_section' => 'Typed by HR']);

    (require database_path('migrations/2026_11_07_150000_set_office_letterhead.php'))->up();

    $letterhead = $settings->fresh()->letterhead();

    expect($letterhead['name'])->toBe(['PHILIPPINE POSTAL CORPORATION', 'AREA 7 EASTERN MINANAO'])
        ->and($letterhead['details'])->toBe(['R. Magsaysay ext. cor. Roxas Avenue, 8000 Davao City', 'hr7official2022@gmail.com'])
        ->and($letterhead['section'])->toBe('Typed by HR');
});

it('prints the letterhead logo large enough to match the office letterhead', function () {
    $html = CertificateRenderer::html([['type' => 'letterhead', 'top' => 'Republic of the Philippines', 'name' => ['PHILIPPINE POSTAL CORPORATION'], 'details' => [], 'section' => '']], ['companyName' => 'x', 'font' => 'calibri', 'letterheadLogoUrl' => '/files/1']);

    expect($html)->toContain('width:225pt;height:48pt');
});

it('uses the wide PHLPOST logo as the letterhead logo and keeps the previous file', function () {
    Storage::fake('local');
    $old = Media::factory()->create();
    $settings = SiteSetting::current();
    $settings->update(['letterhead_logo_id' => $old->id]);

    (require database_path('migrations/2026_11_07_160000_set_office_letterhead_logo.php'))->up();

    $logo = Media::query()->findOrFail($settings->fresh()->letterhead_logo_id);

    expect($logo->id)->not->toBe($old->id)
        ->and($logo->mime_type)->toBe('image/png')
        ->and($logo->is_public)->toBeTrue()
        ->and($old->fresh())->not->toBeNull();

    Storage::disk('local')->assertExists($logo->path);
});

it('replaces the stored letterhead logo file in place', function () {
    Storage::fake('local');
    Storage::disk('local')->put('media/old.png', 'old');
    $logo = Media::factory()->create(['filename' => 'phlpost-letterhead-logo.png', 'path' => 'media/old.png', 'size' => 3]);

    (require database_path('migrations/2026_11_08_090000_replace_office_letterhead_logo_file.php'))->up();

    expect($logo->fresh()->size)->toBe(filesize(resource_path('branding/phlpost-letterhead-logo.png')))
        ->and(Storage::disk('local')->get('media/old.png'))->not->toBe('old');
});
