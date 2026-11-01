<?php

use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Enums\Role;
use App\Exceptions\UserFacingException;
use App\Models\Branch;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\DocumentSequence;
use App\Models\Employee;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\PdfRenderer;

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
        ->and(certificateText($certificate))->toContain('**Ms. MARIA S. REYES**')
        ->toContain('housing loan')
        ->toContain('Issued this 4th day of October 2026.')
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

it('writes the separation wording and the length of service for former employees', function () {
    $employee = certificateEmployee(['employment_status' => EmploymentStatus::Resigned, 'last_day_of_service' => '2026-09-05']);

    $certificate = app(CertificateService::class)->issue($this->hr, certificateTemplate('coe-separated')->id, $employee->id, null, '2026-10-04');

    expect(certificateText($certificate))->toContain('until she resigned on September 5, 2026, a total service of 8 years and 6 months.');
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
