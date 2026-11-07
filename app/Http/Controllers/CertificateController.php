<?php

namespace App\Http\Controllers;

use App\Enums\CertificateStatus;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Employee;
use App\Services\CertificateService;
use App\Services\PdfRenderer;
use App\Support\CertificateRenderer;
use App\Support\Choices;
use App\Support\HrDate;
use App\Support\PageSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CertificateController extends Controller
{
    use RespondsToForms;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Certificate::class);

        $search = mb_substr(trim((string) $request->query('q')), 0, 100);
        $templateId = (int) $request->query('template') ?: null;
        $status = CertificateStatus::tryFrom((string) $request->query('status'));

        $certificates = Certificate::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('control_number', 'like', "%{$search}%")
                ->orWhere('employee_name', 'like', "%{$search}%")
                ->orWhere('purpose', 'like', "%{$search}%")))
            ->when($templateId, fn ($query) => $query->where('template_id', $templateId))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->orderByDesc('id')
            ->paginate(PageSize::resolve($request, 25))
            ->withQueryString();

        return view('certificates.index', [
            'certificates' => $certificates,
            'templates' => CertificateTemplate::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'search' => $search,
            'templateId' => $templateId,
            'status' => $status,
            'filtered' => $search !== '' || $templateId !== null || $status !== null,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Certificate::class);

        $templates = CertificateTemplate::query()->where('active', true)->orderBy('sort_order')->orderBy('name')->get();

        return view('certificates.create', [
            'employees' => Choices::employees(),
            'templates' => $templates,
            'employeeId' => (int) old('employee', $request->query('employee')) ?: null,
            'templateId' => (int) old('template', $request->query('template')) ?: $templates->first()?->id,
            'purpose' => old('purpose', mb_substr((string) $request->query('purpose'), 0, 200)),
            'issuedDate' => old('issued_date', HrDate::today()),
        ]);
    }

    public function store(Request $request, CertificateService $certificates): RedirectResponse
    {
        Gate::authorize('create', Certificate::class);

        try {
            $certificate = $certificates->issue(
                $request->user(),
                $request->integer('template') ?: null,
                $request->integer('employee') ?: null,
                mb_substr(trim((string) $request->input('purpose')), 0, 200),
                (string) $request->input('issued_date'),
            );
        } catch (UserFacingException $exception) {
            return $this->refused($exception->getMessage());
        }

        return $this->flash(redirect()->route('certificates.show', $certificate), "Certificate {$certificate->control_number} issued. Print it or download the PDF.");
    }

    /**
     * The certificate as it would be issued, as an HTML fragment for the live preview.
     */
    public function preview(Request $request, CertificateService $certificates): Response
    {
        Gate::authorize('create', Certificate::class);

        $template = CertificateTemplate::query()->find($request->integer('template'));
        $employee = Employee::query()->find($request->integer('employee'));

        if ($template === null || $employee === null) {
            return response()->noContent();
        }

        $issuedDate = (string) $request->query('issued_date');
        $issuedDate = HrDate::isValid($issuedDate) ? $issuedDate : HrDate::today();

        try {
            $content = $certificates->compose(
                $template,
                $employee,
                mb_substr((string) $request->query('purpose'), 0, 200),
                $issuedDate,
                $template->prefix.'-'.substr($issuedDate, 0, 4).'-????',
            );
        } catch (UserFacingException $exception) {
            return response($exception->getMessage(), 400, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response($this->renderHtml($content), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Paper' => $content['paperSize'],
            'X-Margin' => $content['margin'],
        ]);
    }

    public function show(Certificate $certificate): View
    {
        Gate::authorize('view', $certificate);

        return view('certificates.show', ['certificate' => $certificate, 'body' => $this->renderHtml($certificate->content)]);
    }

    public function void(Request $request, Certificate $certificate, CertificateService $certificates): RedirectResponse
    {
        Gate::authorize('update', $certificate);

        try {
            $certificates->void($certificate, (string) $request->input('reason'));
        } catch (UserFacingException $exception) {
            return $this->refused($exception->getMessage());
        }

        return $this->flash(redirect()->route('certificates.show', $certificate), 'Certificate voided. It now prints with a VOID mark.');
    }

    public function destroy(Certificate $certificate): RedirectResponse
    {
        Gate::authorize('delete', $certificate);

        $certificate->delete();

        return $this->flash(redirect()->route('certificates.index'), "Certificate {$certificate->control_number} was deleted. Its number will not be used again.");
    }

    public function print(Certificate $certificate): View
    {
        Gate::authorize('view', $certificate);

        return view('certificates.print', ['certificate' => $certificate, 'body' => $this->renderHtml($certificate->content)]);
    }

    public function pdf(Certificate $certificate, PdfRenderer $pdf): Response
    {
        Gate::authorize('view', $certificate);

        return response($pdf->certificate($certificate), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->certificateFilename($certificate).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function renderHtml(array $content): string
    {
        $letterheadLogo = CertificateRenderer::letterheadLogoId($content['blocks']);

        return CertificateRenderer::html($content['blocks'], [
            'companyName' => $content['companyName'],
            'font' => $content['font'],
            'logoUrl' => $content['logoId'] ? route('files.show', $content['logoId'], false) : null,
            'letterheadLogoUrl' => $letterheadLogo ? route('files.show', $letterheadLogo, false) : null,
        ]);
    }
}
