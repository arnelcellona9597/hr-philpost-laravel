<?php

namespace App\Http\Controllers;

use App\Enums\PayrollStatus;
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
use App\Support\PayrollMonthly;
use App\Support\PayrollRegister;
use App\Support\PayrollReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollController extends Controller
{
    use RespondsToForms;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', PayrollPeriod::class);

        $filters = [
            'year' => (int) $request->query('year') ?: null,
            'month' => in_array((int) $request->query('month'), range(1, 12), true) ? (int) $request->query('month') : null,
            'half' => in_array((int) $request->query('half'), [1, 2], true) ? (int) $request->query('half') : null,
            'status' => PayrollStatus::tryFrom((string) $request->query('status')),
        ];

        $periods = PayrollPeriod::query()
            ->withCount(['payslips', 'payslips as corrected_count' => fn ($query) => $query->where('corrected_after_release', true)])
            ->withSum('payslips as gross_total', 'gross_pay_centavos')
            ->withSum('payslips as net_total', 'net_pay_centavos')
            ->when($filters['year'], fn ($query, int $year) => $query->whereYear('period_start', $year))
            ->when($filters['month'], fn ($query, int $month) => $query->whereMonth('period_start', $month))
            ->when($filters['half'], fn ($query, int $half) => $half === 1 ? $query->whereDay('period_start', '<=', 15) : $query->whereDay('period_start', '>', 15))
            ->when($filters['status'], fn ($query, PayrollStatus $status) => $query->where('status', $status))
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->get();

        return view('payroll.index', [
            'months' => $periods->groupBy(fn (PayrollPeriod $period): string => $period->period_start->format('Y-m')),
            'filters' => $filters,
            'years' => PayrollPeriod::query()->pluck('period_start')->map(fn ($date): int => (int) Carbon::parse($date)->format('Y'))->push((int) substr(HrDate::today(), 0, 4))->unique()->sortDesc()->values(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', PayrollPeriod::class);

        $today = Carbon::parse(HrDate::today());

        return view('payroll.form', ['period' => null, 'values' => [
            'year' => (int) $request->query('year', $today->year),
            'month' => (int) $request->query('month', $today->month),
            'half' => (int) $request->query('half', $today->day <= 15 ? 1 : 2),
        ]]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', PayrollPeriod::class);

        ['data' => $data, 'errors' => $errors] = $this->parse($request);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $period = PayrollPeriod::query()->create($data);

        return $this->flash(redirect()->route('payroll.show', $period), "Payroll period {$period->name} created. Next: add the COS workers.");
    }

    public function show(PayrollPeriod $period): View
    {
        Gate::authorize('view', $period);

        $slips = $period->payslips()->with(['wageOrder', 'employee.station', 'employee.unit'])->orderBy('station')->orderBy('full_name')->get();

        return view('payroll.show', [
            'period' => $period->load(['reviewedBy', 'approvedBy', 'releasedBy']),
            'settings' => PayslipSetting::current(),
            'slips' => $slips,
            'review' => PayrollReview::build($slips, $period->period_end->toDateString()),
            'branches' => Choices::branches(),
        ]);
    }

    public function edit(PayrollPeriod $period): View
    {
        Gate::authorize('update', $period);

        return view('payroll.form', ['period' => $period, 'values' => [
            'year' => $period->year ?? $period->period_start->year,
            'month' => $period->month ?? $period->period_start->month,
            'half' => $period->half ?? ($period->period_start->day <= 15 ? 1 : 2),
            'pay_date' => $period->pay_date?->toDateString(),
            'batch' => $period->batch,
            'notes' => $period->notes,
        ]]);
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
            $result['regional'] > 0 ? "{$result['regional']} worker(s) have no contract rate, so the regional minimum of their station's region was used." : null,
            $result['without_rate'] > 0 ? "{$result['without_rate']} worker(s) have no rate at all: add their pay history or their region's wage order, then use Refresh rates." : null,
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

        $missing = $result['without_rate'] > 0 ? " {$result['without_rate']} worker(s) still have no rate: add their pay history or their region's wage order." : '';

        return $this->flash(redirect()->route('payroll.show', $period), "Daily rates refreshed: {$result['updated']} payslip(s) changed.{$missing}");
    }

    /**
     * The grid arrives as one JSON field: a large period has more inputs than PHP's max_input_vars,
     * which would otherwise drop values without a word.
     */
    public function saveGrid(Request $request, PayrollPeriod $period, PayrollService $payroll): RedirectResponse
    {
        Gate::authorize('update', $period);

        if ($period->isReleased()) {
            Gate::authorize('correctReleased', $period);
        }

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

    public function review(Request $request, PayrollPeriod $period, PayrollService $payroll): RedirectResponse
    {
        Gate::authorize('review', $period);

        return $this->step($period, fn () => $payroll->review($period, $request->user()), "{$period->name} marked as reviewed. A System Admin can now approve it.");
    }

    public function approve(Request $request, PayrollPeriod $period, PayrollService $payroll): RedirectResponse
    {
        Gate::authorize('approve', $period);

        return $this->step($period, fn () => $payroll->approve($period, $request->user(), $request->boolean('confirm_below_minimum')), "{$period->name} approved. It can now be released.");
    }

    public function returnToDraft(PayrollPeriod $period, PayrollService $payroll): RedirectResponse
    {
        Gate::authorize('returnToDraft', $period);

        return $this->step($period, fn () => $payroll->returnToDraft($period), "{$period->name} is a draft again and can be changed.");
    }

    public function release(Request $request, PayrollPeriod $period, PayrollService $payroll): RedirectResponse
    {
        Gate::authorize('release', $period);

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

        return view('payroll.register', [
            'period' => $period,
            'register' => PayrollRegister::build($period),
            'letterhead' => $this->letterhead(),
        ]);
    }

    public function registerPdf(PayrollPeriod $period, PdfRenderer $pdf): Response
    {
        Gate::authorize('view', $period);

        return $this->pdf($pdf->payrollRegister($period), $pdf->payrollRegisterFilename($period));
    }

    public function export(PayrollPeriod $period, PayrollWorkbook $workbook): StreamedResponse
    {
        Gate::authorize('view', $period);

        return $this->xlsx($workbook->export($period), $workbook->filename($period));
    }

    public function monthly(int $year, int $month): View
    {
        Gate::authorize('viewAny', PayrollPeriod::class);

        return view('payroll.monthly', ['summary' => PayrollMonthly::build($year, $month), 'letterhead' => $this->letterhead()]);
    }

    public function monthlyPdf(int $year, int $month, PdfRenderer $pdf): Response
    {
        Gate::authorize('viewAny', PayrollPeriod::class);

        $summary = PayrollMonthly::build($year, $month);

        return $this->pdf($pdf->payrollMonthly($summary), sprintf('payroll-summary-%04d-%02d.pdf', $year, $month));
    }

    public function monthlyExport(int $year, int $month, PayrollWorkbook $workbook): StreamedResponse
    {
        Gate::authorize('viewAny', PayrollPeriod::class);

        return $this->xlsx($workbook->monthly(PayrollMonthly::build($year, $month)), sprintf('payroll-summary-%04d-%02d.xlsx', $year, $month));
    }

    /**
     * Run one workflow step and report back on the period page.
     */
    private function step(PayrollPeriod $period, callable $action, string $done): RedirectResponse
    {
        try {
            $action();
        } catch (UserFacingException $exception) {
            return $this->flash(redirect()->route('payroll.show', $period), $exception->getMessage(), 'error');
        }

        return $this->flash(redirect()->route('payroll.show', $period), $done);
    }

    private function letterhead(): string
    {
        $logoId = SiteSetting::current()->letterhead()['logoId'];

        return PayrollRegister::letterhead($logoId ? route('files.show', $logoId, false) : null, null, false);
    }

    private function pdf(string $bytes, string $filename): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function xlsx(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The period form: month, year and which half; the system sets the dates, name and code. The
     * month and half are fixed once the period has payslips or has left draft.
     *
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    private function parse(Request $request, ?PayrollPeriod $period = null): array
    {
        $errors = [];
        $data = [];
        $locked = $period !== null && (! $period->isDraft() || $period->payslips()->exists());

        if (! $locked) {
            $year = (int) $request->input('year');
            $month = (int) $request->input('month');
            $half = (int) $request->input('half');

            if ($year < 2000 || $year > 2100) {
                $errors['year'] = 'Enter a year between 2000 and 2100';
            }

            if ($month < 1 || $month > 12) {
                $errors['month'] = 'Choose a month';
            }

            if (! in_array($half, [1, 2], true)) {
                $errors['half'] = 'Choose the 1st (1–15) or 2nd (16–end) half';
            }

            if ($errors === []) {
                $data = PayrollPeriod::forHalf($year, $month, $half);

                $taken = PayrollPeriod::query()
                    ->where(fn ($query) => $query->where(['year' => $year, 'month' => $month, 'half' => $half])
                        ->orWhere('code', $data['code'])
                        ->orWhere(fn ($query) => $query->whereDate('period_start', $data['period_start'])->whereDate('period_end', $data['period_end'])))
                    ->when($period, fn ($query) => $query->whereKeyNot($period->id))
                    ->exists();

                if ($taken) {
                    $errors['half'] = "{$data['name']} already exists. Open it from the payroll list instead.";
                }
            }
        }

        $payDate = trim((string) $request->input('pay_date'));

        if (! HrDate::isValid($payDate)) {
            $errors['pay_date'] = 'Enter the pay date';
        }

        $data['pay_date'] = $payDate;
        $data['batch'] = mb_substr(trim((string) $request->input('batch')), 0, 20) ?: null;
        $data['notes'] = trim((string) $request->input('notes')) ?: null;

        return ['data' => $data, 'errors' => $errors];
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
