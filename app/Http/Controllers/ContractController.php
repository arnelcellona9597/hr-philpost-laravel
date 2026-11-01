<?php

namespace App\Http\Controllers;

use App\Enums\ContractStatus;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Contract;
use App\Models\ContractSetting;
use App\Models\ContractTemplate;
use App\Models\Employee;
use App\Services\ContractService;
use App\Services\ContractWordExporter;
use App\Services\PdfRenderer;
use App\Support\CertificateRenderer;
use App\Support\HrDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class ContractController extends Controller
{
    use RespondsToForms;

    private const PER_PAGE = 25;

    private const MAX_ZIP = 40;

    private const ENDING_SOON_DAYS = 30;

    public function __construct(private readonly ContractService $contracts) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Contract::class);

        $search = mb_substr(trim((string) $request->query('q')), 0, 100);
        $templateId = (int) $request->query('template') ?: null;
        $status = ContractStatus::tryFrom((string) $request->query('status'));
        $ending = $request->boolean('ending');
        $today = HrDate::today();
        $soon = HrDate::addDays($today, self::ENDING_SOON_DAYS);
        $endingSoon = fn (): Builder => Contract::query()->where('status', ContractStatus::Valid->value)->whereBetween('term_end', [$today, $soon]);

        $contracts = Contract::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';

                $query->where(fn (Builder $any) => $any->where('control_number', 'like', $like)->orWhere('employee_name', 'like', $like)->orWhere('position', 'like', $like)->orWhere('office', 'like', $like));
            })
            ->when($templateId, fn (Builder $query) => $query->where('template_id', $templateId))
            ->when($status, fn (Builder $query) => $query->where('status', $status->value))
            ->when($ending, fn (Builder $query) => $query->where('status', ContractStatus::Valid->value)->whereBetween('term_end', [$today, $soon]))
            ->orderByDesc('term_start')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('contracts.index', [
            'contracts' => $contracts,
            'templates' => ContractTemplate::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'search' => $search,
            'templateId' => $templateId,
            'status' => $status,
            'ending' => $ending,
            'endingCount' => $endingSoon()->count(),
            'today' => $today,
            'soon' => $soon,
            'filtered' => $search !== '' || $templateId !== null || $status !== null || $ending,
            'maxZip' => self::MAX_ZIP,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Contract::class);

        $templates = ContractTemplate::query()->where('active', true)->orderBy('sort_order')->orderBy('name')->get();
        $settings = ContractSetting::current();
        $start = (string) old('term_start', $request->query('term_start'));
        $end = (string) old('term_end', $request->query('term_end'));
        $ready = HrDate::isValid($start) && HrDate::isValid($end) && $end >= $start;
        $chosen = Employee::query()->with('station')->find((int) $request->query('employee'));
        $people = $ready ? $this->contracts->candidates($start, $end) : ($chosen === null ? [] : [$chosen]);

        if ($chosen !== null && ! collect($people)->contains('id', $chosen->id)) {
            $people[] = $chosen;
        }

        return view('contracts.create', [
            'templates' => $templates,
            'templateId' => (int) old('template', $request->query('template')) ?: $templates->first()?->id,
            'termStart' => $start,
            'termEnd' => $end,
            'issuedDate' => old('issued_date', HrDate::today()),
            'settings' => $settings,
            'ready' => $ready,
            'tooMany' => count($people) > ContractService::MAX_BULK,
            'rows' => array_map(fn (Employee $employee): array => ['employee' => $employee, ...$this->contracts->defaults($employee, $settings, $start, $end), 'checked' => $chosen === null || $employee->id === $chosen->id], array_slice($people, 0, ContractService::MAX_BULK)),
            'officialsMissing' => collect(array_keys(ContractSetting::OFFICIALS))->filter(fn (string $key): bool => trim((string) $settings->{"{$key}_name"}) === '')->isNotEmpty(),
        ]);
    }

    /**
     * Issue the ticked rows of the grid. The rows travel as one JSON field because a long list has more inputs than PHP accepts.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Contract::class);

        $grid = json_decode((string) $request->input('grid'), true);

        if (! is_array($grid) || ! array_is_list($grid)) {
            return $this->refused('The list could not be read. Reload the page and try again.');
        }

        $shared = [
            'term_start' => $request->input('term_start'),
            'term_end' => $request->input('term_end'),
            'second_wage' => $request->input('second_wage'),
            'second_wage_from' => $request->input('second_wage_from'),
            'wage_order' => $request->input('wage_order'),
            'premium_percent' => $request->input('premium_percent', ContractSetting::current()->premium_percent),
        ];
        $rows = array_map(fn (array $row): array => [...$shared, ...array_intersect_key($row, array_flip(['employee', 'position', 'office', 'place_of_service', 'address', 'daily_wage']))], array_values(array_filter($grid, fn (mixed $row): bool => is_array($row) && ($row['selected'] ?? false))));

        try {
            $issued = $this->contracts->issueMany($request->user(), $request->integer('template') ?: null, $rows, (string) $request->input('issued_date'));
        } catch (UserFacingException $exception) {
            return $this->refused($exception->getMessage());
        }

        if (count($issued) === 1) {
            return $this->flash(redirect()->route('contracts.show', $issued[0]), "Contract {$issued[0]->control_number} issued. Print it or download the PDF or Word file.");
        }

        return $this->flash(redirect()->route('contracts.index'), count($issued).' contracts issued, '.$issued[0]->control_number.' to '.end($issued)->control_number.'.');
    }

    public function show(Contract $contract): View
    {
        Gate::authorize('view', $contract);

        return view('contracts.show', ['contract' => $contract, 'body' => $this->renderHtml($contract->content)]);
    }

    public function print(Contract $contract): View
    {
        Gate::authorize('view', $contract);

        return view('contracts.print', ['contract' => $contract, 'body' => $this->renderHtml($contract->content)]);
    }

    public function pdf(Contract $contract, PdfRenderer $pdf): Response
    {
        Gate::authorize('view', $contract);

        return response($pdf->contract($contract), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->contractFilename($contract).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function docx(Contract $contract, ContractWordExporter $word): Response
    {
        Gate::authorize('view', $contract);

        return response($word->render($contract), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="'.$word->filename($contract).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The chosen contracts as one ZIP of PDF or Word files.
     */
    public function download(Request $request, PdfRenderer $pdf, ContractWordExporter $word): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('viewAny', Contract::class);

        $ids = array_slice(array_filter(array_map('intval', (array) $request->input('ids'))), 0, self::MAX_ZIP + 1);
        $format = $request->input('format') === 'docx' ? 'docx' : 'pdf';

        if ($ids === []) {
            return $this->flash(redirect()->route('contracts.index'), 'Tick the contracts you want to download.', 'error');
        }

        if (count($ids) > self::MAX_ZIP) {
            return $this->flash(redirect()->route('contracts.index'), 'Download at most '.self::MAX_ZIP.' contracts at a time.', 'error');
        }

        $path = tempnam(sys_get_temp_dir(), 'contracts');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The ZIP file could not be created.');
        }

        foreach (Contract::query()->whereIn('id', $ids)->orderBy('control_number')->get() as $contract) {
            $zip->addFromString($format === 'pdf' ? $pdf->contractFilename($contract) : $word->filename($contract), $format === 'pdf' ? $pdf->contract($contract) : $word->render($contract));
        }

        $zip->close();

        return response()->download($path, 'contracts-'.HrDate::today()."-{$format}.zip", ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    public function void(Request $request, Contract $contract): RedirectResponse
    {
        Gate::authorize('update', $contract);

        try {
            $this->contracts->void($contract, (string) $request->input('reason'));
        } catch (UserFacingException $exception) {
            return $this->refused($exception->getMessage());
        }

        return $this->flash(redirect()->route('contracts.show', $contract), 'Contract voided. It now prints with a VOID mark.');
    }

    public function destroy(Contract $contract): RedirectResponse
    {
        Gate::authorize('delete', $contract);

        $contract->delete();

        return $this->flash(redirect()->route('contracts.index'), "Contract {$contract->control_number} was deleted. Its number will not be used again.");
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function renderHtml(array $content): string
    {
        return CertificateRenderer::html($content['blocks'], [
            'companyName' => $content['companyName'],
            'font' => $content['font'],
            'logoUrl' => $content['logoId'] ? route('files.show', $content['logoId'], false) : null,
        ]);
    }
}
