<?php

namespace App\Http\Controllers;

use App\Enums\RemittanceKind;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Remittance;
use App\Models\RemittanceSetting;
use App\Models\SiteSetting;
use App\Models\VoucherSetting;
use App\Services\PdfRenderer;
use App\Services\RemittanceService;
use App\Services\RemittancesWorkbook;
use App\Support\HrDate;
use App\Support\Money;
use App\Support\PageSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RemittanceController extends Controller
{
    use RespondsToForms;

    private const PER_PAGE = 24;

    public function __construct(private readonly RemittanceService $service) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Remittance::class);

        $kind = RemittanceKind::tryFrom((string) $request->query('kind')) ?? RemittanceKind::Sss;
        $remittances = Remittance::query()->where('kind', $kind->value)->withCount('lines')->orderByDesc('year')->orderByDesc('month')->paginate(PageSize::resolve($request, self::PER_PAGE))->withQueryString();
        $latest = Remittance::query()->where('kind', $kind->value)->orderByDesc('year')->orderByDesc('month')->first();
        $next = $latest === null ? [(int) substr(HrDate::today(), 0, 4), (int) substr(HrDate::today(), 5, 2)] : ($latest->month === 12 ? [$latest->year + 1, 1] : [$latest->year, $latest->month + 1]);

        return view('remittances.index', [
            'kind' => $kind,
            'kinds' => RemittanceKind::cases(),
            'remittances' => $remittances,
            'totals' => $remittances->getCollection()->mapWithKeys(fn (Remittance $remittance): array => [$remittance->id => $this->service->totals($remittance)]),
            'nextYear' => $next[0],
            'nextMonth' => $next[1],
            'hasEarlier' => $latest !== null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Remittance::class);

        $kind = RemittanceKind::tryFrom((string) $request->input('kind'));
        $year = (int) $request->input('year');
        $month = (int) $request->input('month');
        $back = redirect()->route('remittances.index', ['kind' => $kind?->value]);

        if ($kind === null) {
            return $this->flash($back, 'Choose which fund this is for.', 'error');
        }

        try {
            $result = $this->service->generate($kind, $year, $month, $request->user());
        } catch (UserFacingException $exception) {
            return $this->flash($back, $exception->getMessage(), 'error');
        }

        $note = $result['copied'].' '.($result['copied'] === 1 ? 'person' : 'people').' listed'.($result['dropped'] > 0 ? "; {$result['dropped']} who have left were not copied" : '').'.';

        return $this->flash(redirect()->route('remittances.show', $result['remittance']), "{$result['remittance']->label} started. {$note}");
    }

    public function show(Remittance $remittance): View
    {
        Gate::authorize('view', $remittance);

        $lines = $remittance->lines()->get();

        return view('remittances.show', [
            'remittance' => $remittance,
            'lines' => $lines,
            'totals' => $this->service->totals($remittance),
            'problems' => $this->service->problems($remittance),
            'missing' => $remittance->isFinalized() ? 0 : count($this->service->missing($remittance)),
            'setting' => RemittanceSetting::forKind($remittance->kind),
            'voucher' => VoucherSetting::current(),
        ]);
    }

    /**
     * Save the whole grid. The rows travel as one JSON field because a long list has more inputs than PHP accepts.
     */
    public function update(Request $request, Remittance $remittance): RedirectResponse
    {
        Gate::authorize('update', $remittance);

        $rows = json_decode((string) $request->input('grid'), true);
        $back = redirect()->route('remittances.show', $remittance);

        if (! is_array($rows) || ! array_is_list($rows)) {
            return $this->refused('The list could not be read. Reload the page and try again.');
        }

        try {
            $count = $this->service->saveLines($remittance, $rows);
        } catch (UserFacingException $exception) {
            return $this->refused($exception->getMessage());
        }

        return $this->flash($back, "Saved {$count} ".($count === 1 ? 'person' : 'people').'.');
    }

    public function addMissing(Remittance $remittance): RedirectResponse
    {
        Gate::authorize('update', $remittance);

        try {
            $added = $this->service->addMissing($remittance);
        } catch (UserFacingException $exception) {
            return $this->flash(redirect()->route('remittances.show', $remittance), $exception->getMessage(), 'error');
        }

        return $this->flash(redirect()->route('remittances.show', $remittance), $added === 0 ? 'Everyone eligible is already listed.' : "Added {$added} ".($added === 1 ? 'person' : 'people').'.');
    }

    public function voucherDetails(Request $request, Remittance $remittance): RedirectResponse
    {
        Gate::authorize('update', $remittance);

        $date = trim((string) $request->input('voucher_date'));

        if ($date !== '' && ! HrDate::isValid($date)) {
            return $this->invalid(['voucher_date' => 'Enter the voucher date as YYYY-MM-DD.']);
        }

        $remittance->update([
            'voucher_number' => mb_substr(trim((string) $request->input('voucher_number')), 0, 40) ?: null,
            'voucher_date' => $date ?: null,
            'mode_of_payment' => mb_substr(trim((string) $request->input('mode_of_payment')), 0, 40) ?: null,
            'notes' => mb_substr(trim((string) $request->input('notes')), 0, 2000) ?: null,
        ]);

        return $this->flash(redirect()->route('remittances.show', $remittance), 'Voucher details saved.');
    }

    public function finalize(Remittance $remittance): RedirectResponse
    {
        Gate::authorize('update', $remittance);

        try {
            $this->service->finalize($remittance);
        } catch (UserFacingException $exception) {
            return $this->flash(redirect()->route('remittances.show', $remittance), $exception->getMessage(), 'error');
        }

        return $this->flash(redirect()->route('remittances.show', $remittance), "{$remittance->label} is finalized.");
    }

    public function reopen(Remittance $remittance): RedirectResponse
    {
        Gate::authorize('update', $remittance);

        $this->service->reopen($remittance);

        return $this->flash(redirect()->route('remittances.show', $remittance), "{$remittance->label} is open for changes again.");
    }

    public function destroy(Remittance $remittance): RedirectResponse
    {
        Gate::authorize('delete', $remittance);

        if ($remittance->isFinalized()) {
            return $this->flash(redirect()->route('remittances.show', $remittance), 'Reopen this month before deleting it.', 'error');
        }

        $kind = $remittance->kind;
        $remittance->delete();

        return $this->flash(redirect()->route('remittances.index', ['kind' => $kind->value]), 'The month was deleted.');
    }

    public function export(Remittance $remittance, RemittancesWorkbook $workbook): StreamedResponse
    {
        Gate::authorize('view', $remittance);

        $spreadsheet = $workbook->export($remittance);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $workbook->filename($remittance), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function print(Remittance $remittance): View
    {
        Gate::authorize('view', $remittance);

        return view('remittances.print', [
            'remittance' => $remittance,
            'lines' => $remittance->lines()->get(),
            'totals' => $this->service->totals($remittance),
            'setting' => RemittanceSetting::forKind($remittance->kind),
            'site' => SiteSetting::current(),
        ]);
    }

    public function voucher(Remittance $remittance): View
    {
        Gate::authorize('view', $remittance);

        return view('remittances.voucher', $this->voucherData($remittance));
    }

    public function voucherPdf(Remittance $remittance, PdfRenderer $pdf): Response
    {
        Gate::authorize('view', $remittance);

        return response($pdf->voucher($this->voucherData($remittance)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->voucherFilename($remittance).'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function voucherData(Remittance $remittance): array
    {
        $totals = $this->service->totals($remittance);
        $setting = RemittanceSetting::forKind($remittance->kind);
        $officials = VoucherSetting::current();

        return [
            'remittance' => $remittance,
            'totals' => $totals,
            'setting' => $setting,
            'officials' => $officials,
            'site' => SiteSetting::current(),
            'mode' => $remittance->mode_of_payment ?: $officials->mode_of_payment,
            'particulars' => $this->service->particulars($remittance, $totals['payable']),
            'words' => Money::words($totals['payable']),
        ];
    }
}
