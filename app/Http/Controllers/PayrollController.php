<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\PayrollPeriod;
use App\Models\PayslipSetting;
use App\Models\SiteSetting;
use App\Services\PayrollService;
use App\Services\PayrollWorkbook;
use App\Services\PdfRenderer;
use App\Support\Choices;
use App\Support\HrDate;
use App\Support\PayrollRegister;
use App\Support\RecordForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollController extends Controller
{
    use RespondsToForms;

    private const UNIQUE = ['code' => 'That code is already used by another period.'];

    private const FIELDS = [
        ['name' => 'name', 'label' => 'Period name', 'kind' => 'text', 'required' => true, 'help' => 'e.g. "October 2026 — 1st half"'],
        ['name' => 'code', 'label' => 'Code', 'kind' => 'text', 'required' => true, 'help' => 'Short and unique, e.g. 2026-10-A'],
        ['name' => 'period_start', 'label' => 'Period start', 'kind' => 'date', 'required' => true],
        ['name' => 'period_end', 'label' => 'Period end', 'kind' => 'date', 'required' => true],
        ['name' => 'pay_date', 'label' => 'Pay date', 'kind' => 'date', 'required' => true],
        ['name' => 'batch', 'label' => 'Batch', 'kind' => 'text', 'help' => 'Printed under the title, e.g. 7 for "BATCH 7"'],
        ['name' => 'notes', 'label' => 'Notes', 'kind' => 'textarea'],
    ];

    public function index(): View
    {
        Gate::authorize('viewAny', PayrollPeriod::class);

        return view('payroll.index', [
            'periods' => PayrollPeriod::query()
                ->withCount(['payslips', 'payslips as corrected_count' => fn ($query) => $query->where('corrected_after_release', true)])
                ->withSum('payslips as gross_total', 'gross_pay_centavos')
                ->withSum('payslips as net_total', 'net_pay_centavos')
                ->orderByDesc('period_start')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', PayrollPeriod::class);

        return view('payroll.form', ['period' => null, 'fields' => self::FIELDS, 'values' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', PayrollPeriod::class);

        ['data' => $data, 'errors' => $errors] = $this->parse($request);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $period = PayrollPeriod::query()->create($data);

        return $this->flash(redirect()->route('payroll.show', $period), "Payroll period {$period->name} created. Next: generate the payslips.");
    }

    public function show(PayrollPeriod $period): View
    {
        Gate::authorize('view', $period);

        return view('payroll.show', [
            'period' => $period,
            'settings' => PayslipSetting::current(),
            'slips' => $period->payslips()->orderBy('station')->orderBy('full_name')->get(),
            'branches' => Choices::branches(),
        ]);
    }

    public function edit(PayrollPeriod $period): View
    {
        Gate::authorize('update', $period);

        return view('payroll.form', ['period' => $period, 'fields' => self::FIELDS, 'values' => RecordForm::values($period, self::FIELDS)]);
    }

    public function update(Request $request, PayrollPeriod $period): RedirectResponse
    {
        Gate::authorize('update', $period);

        ['data' => $data, 'errors' => $errors] = $this->parse($request, $period);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $period->update($data);

        return $this->flash(redirect()->route('payroll.show', $period), "{$period->name} updated.");
    }

    public function destroy(PayrollPeriod $period, PayrollService $payroll): RedirectResponse
    {
        Gate::authorize('delete', $period);

        try {
            $payroll->deletePeriod($period);
        } catch (UserFacingException $exception) {
            return $this->flash(redirect()->route('payroll.show', $period), $exception->getMessage(), 'error');
        }

        return $this->flash(redirect()->route('payroll.index'), "{$period->name} was deleted.");
    }

    public function generate(Request $request, PayrollPeriod $period, PayrollService $payroll): RedirectResponse
    {
        Gate::authorize('update', $period);

        try {
            $result = $payroll->generate($period, array_values(array_filter(array_map('intval', (array) $request->input('branch')))));
        } catch (UserFacingException $exception) {
            return $this->flash(redirect()->route('payroll.show', $period), $exception->getMessage(), 'error');
        }

        $message = array_filter([
            "{$result['created']} COS payslip(s) created.",
            $result['existing'] > 0 ? "{$result['existing']} worker(s) already had one." : null,
            $result['without_rate'] > 0 ? "{$result['without_rate']} worker(s) have no daily rate in their pay history: add it, then use Refresh rates." : null,
        ]);

        return $this->flash(redirect()->route('payroll.show', $period), implode(' ', $message), $result['without_rate'] > 0 ? 'info' : 'success');
    }

    public function refreshRates(PayrollPeriod $period, PayrollService $payroll): RedirectResponse
    {
        Gate::authorize('update', $period);

        try {
            $result = $payroll->refreshRates($period);
        } catch (UserFacingException $exception) {
            return $this->flash(redirect()->route('payroll.show', $period), $exception->getMessage(), 'error');
        }

        $missing = $result['without_rate'] > 0 ? " {$result['without_rate']} worker(s) still have no daily rate in their pay history." : '';

        return $this->flash(redirect()->route('payroll.show', $period), "Daily rates refreshed: {$result['updated']} payslip(s) changed.{$missing}");
    }

    /**
     * The grid arrives as one JSON field: a large period has more inputs than PHP's max_input_vars,
     * which would otherwise drop values without a word.
     */
    public function saveGrid(Request $request, PayrollPeriod $period, PayrollService $payroll): RedirectResponse
    {
        Gate::authorize('update', $period);

        $rows = $this->gridRows((string) $request->input('grid'));

        if ($rows === []) {
            return $this->refused('Nothing was saved because the page sent no amounts. Reload the page and try again.');
        }

        $result = $payroll->saveRows($period, $rows);

        if ($result['errors'] !== []) {
            return back()->withInput()->withErrors(['grid' => array_column($result['errors'], 'message')]);
        }

        return $this->flash(redirect()->route('payroll.show', $period), "Saved {$result['updated']} payslip(s).");
    }

    public function release(Request $request, PayrollPeriod $period, PayrollService $payroll): RedirectResponse
    {
        Gate::authorize('update', $period);

        try {
            $result = $payroll->release($period, $request->user(), $request->boolean('notify'));
        } catch (UserFacingException $exception) {
            return $this->flash(redirect()->route('payroll.show', $period), $exception->getMessage(), 'error');
        }

        $message = array_filter([
            "{$period->name} released ({$result['payslips']} payslips).",
            $result['queued'] > 0 ? "{$result['queued']} payslip email(s) are being sent." : null,
            $result['skipped'] > 0 ? "{$result['skipped']} employee(s) have no email address — print theirs." : null,
        ]);

        return $this->flash(redirect()->route('payroll.show', $period), implode(' ', $message));
    }

    public function destroyPayslips(PayrollPeriod $period, PayrollService $payroll): RedirectResponse
    {
        Gate::authorize('delete', $period);

        try {
            $payroll->deleteAllPayslips($period);
        } catch (UserFacingException $exception) {
            return $this->flash(redirect()->route('payroll.show', $period), $exception->getMessage(), 'error');
        }

        return $this->flash(redirect()->route('payroll.show', $period), 'All payslips in this period were deleted.');
    }

    public function print(PayrollPeriod $period): View
    {
        Gate::authorize('view', $period);

        $site = SiteSetting::current();
        $settings = PayslipSetting::current();

        return view('payroll.print', [
            'period' => $period,
            'settings' => $settings,
            'companyName' => $settings->company_name ?: $site->company_name,
            'logoUrl' => $site->logo_id ? route('files.show', $site->logo_id, false) : null,
            'slips' => $period->payslips()->orderBy('full_name')->get(),
        ]);
    }

    public function register(PayrollPeriod $period): View
    {
        Gate::authorize('view', $period);

        $logoId = SiteSetting::current()->letterhead()['logoId'];

        return view('payroll.register', [
            'period' => $period,
            'register' => PayrollRegister::build($period),
            'letterhead' => PayrollRegister::letterhead($logoId ? route('files.show', $logoId, false) : null, null, false),
        ]);
    }

    public function registerPdf(PayrollPeriod $period, PdfRenderer $pdf): Response
    {
        Gate::authorize('view', $period);

        return response($pdf->payrollRegister($period), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->payrollRegisterFilename($period).'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function export(PayrollPeriod $period, PayrollWorkbook $workbook): StreamedResponse
    {
        Gate::authorize('view', $period);

        $spreadsheet = $workbook->export($period);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $workbook->filename($period), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    private function parse(Request $request, ?PayrollPeriod $period = null): array
    {
        $parsed = RecordForm::parse($request, self::FIELDS, 'payroll_periods', self::UNIQUE, $period);
        $start = $parsed['data']['period_start'] ?? null;
        $end = $parsed['data']['period_end'] ?? null;

        if ($start && $end && HrDate::isValid($start) && HrDate::isValid($end) && $end < $start) {
            $parsed['errors']['period_end'] = 'Period end is before period start';
        }

        return $parsed;
    }

    /**
     * @return list<array{id: int, values: array<string, string>}>
     */
    private function gridRows(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return [];
        }

        $rows = [];

        foreach ($decoded as $row) {
            if (is_array($row) && is_int($row['id'] ?? null) && is_array($row['values'] ?? null)) {
                $rows[] = ['id' => $row['id'], 'values' => array_map(strval(...), array_filter($row['values'], is_scalar(...)))];
            }
        }

        return $rows;
    }
}
