<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Employee;
use App\Models\ImportJob;
use App\Services\DataImporter;
use App\Services\DataWorkbook;
use App\Services\PendingImports;
use App\Support\EmployeeSearch;
use App\Support\HrDate;
use App\Support\ImportModules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataController extends Controller
{
    use RespondsToForms;

    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    private const HINT = 'data:';

    private const YEARLY = ['wellness-leaves', 'itr-submissions', 'sworn-declarations', 'pds-submissions', 'ipcr-ratings'];

    public function index(): View
    {
        Gate::authorize('viewAny', Employee::class);

        return view('data.index', [
            'modules' => array_map(fn (array $module): array => [
                'module' => $module,
                'count' => ImportModules::MODELS[$module['slug']]::query()->count(),
                'yearly' => in_array($module['slug'], self::YEARLY, true),
            ], ImportModules::all()),
            'year' => (int) substr(HrDate::today(), 0, 4),
            'history' => ImportJob::query()->latest('id')->limit(10)->get(),
        ]);
    }

    /**
     * Excel download of all data, one module, or a module filtered like the page it came from.
     */
    public function export(Request $request, DataWorkbook $workbook): StreamedResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $slug = (string) $request->query('module', 'all');

        if ($slug === 'all') {
            $requests = array_map(fn (array $module): array => ['module' => $module, 'query' => $workbook->query($module)], ImportModules::all());
            $name = 'hr-data-all';
        } else {
            $module = ImportModules::find($slug);
            abort_if($module === null, 400, 'Unknown module');

            [$query, $filtered] = $this->filtered($request, $module, $workbook->query($module));
            $year = $this->year($request);
            $requests = [['module' => $module, 'query' => $query]];
            $name = 'hr-'.Str::slug($module['sheet']).($year && in_array($slug, self::YEARLY, true) ? "-{$year}" : ($filtered ? '-filtered' : ''));
        }

        return $this->download($workbook->build($requests), $workbook->filename($name));
    }

    public function template(Request $request, DataWorkbook $workbook): StreamedResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $module = $request->query('module') ? ImportModules::find((string) $request->query('module')) : null;
        $name = $module ? 'template-'.Str::slug($module['sheet']) : 'template-all';

        return $this->download($workbook->template($module ? [$module] : ImportModules::all()), "hr-{$name}.xlsx");
    }

    /**
     * Check an uploaded workbook. Nothing is saved until the person confirms the result.
     */
    public function preview(Request $request, DataImporter $importer, PendingImports $imports): View|RedirectResponse
    {
        Gate::authorize('create', Employee::class);

        $file = $request->file('file');

        if (! $file instanceof UploadedFile || ! $file->isValid() || (int) $file->getSize() === 0) {
            return $this->flash(redirect()->route('data.index'), 'Choose an Excel file to upload.', 'error');
        }

        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->flash(redirect()->route('data.index'), 'The file is larger than 10 MB.', 'error');
        }

        if (! preg_match('/\.xlsx$/i', $file->getClientOriginalName())) {
            return $this->flash(redirect()->route('data.index'), 'Upload an .xlsx workbook (older .xls files must be re-saved as .xlsx).', 'error');
        }

        $moduleHint = (string) $request->input('module');
        $plan = $importer->validate($file->getRealPath(), $moduleHint ?: null, $request->user());
        $token = $imports->stage((string) file_get_contents($file->getRealPath()), $request->user()->id, $file->getClientOriginalName(), self::HINT.$moduleHint);

        return view('data.import', ['plan' => $plan, 'token' => $token, 'fileName' => $file->getClientOriginalName(), 'reason' => null]);
    }

    /**
     * Check the file again, because the data may have changed since the preview, then save it.
     */
    public function commit(Request $request, DataImporter $importer, PendingImports $imports): View|RedirectResponse
    {
        Gate::authorize('create', Employee::class);

        $token = (string) $request->input('token');
        $staged = $imports->claim($token, $request->user()->id, self::HINT);

        if ($staged === null) {
            return $this->flash(redirect()->route('data.index'), 'This upload has expired or was already imported. Upload the file again.', 'error');
        }

        $fileName = $staged['meta']['file_name'];
        $plan = $importer->validate($staged['path'], substr($staged['meta']['hint'], strlen(self::HINT)) ?: null, $request->user());

        if ($plan['errors'] !== []) {
            $imports->release($token);

            return view('data.import', ['plan' => $plan, 'token' => $token, 'fileName' => $fileName, 'reason' => 'The data changed since the preview and the file no longer passes all checks.']);
        }

        $archive = 'import-'.now()->format('Y-m-d\THis').'-'.substr($token, 0, 8).'.xlsx';
        $result = $importer->commit($plan, $request->user(), $fileName, $archive);

        if ($result['issue'] !== null) {
            $imports->release($token);
            $issue = $result['issue'];

            return $this->flash(redirect()->route('data.index'), "Nothing was imported. {$issue['sheet']} row ".($issue['row'] ?? '?').": {$issue['message']}", 'error');
        }

        $imports->finish($token, $archive);

        return $this->flash(redirect()->route('data.index'), "Imported {$fileName}: {$result['created']} new and {$result['updated']} updated record(s); {$result['unchanged']} unchanged.");
    }

    /**
     * @param  array<string, mixed>  $module
     * @param  Builder<Model>  $query
     * @return array{0: Builder<Model>, 1: bool}
     */
    private function filtered(Request $request, array $module, Builder $query): array
    {
        $year = $this->year($request);
        $slug = $module['slug'];

        if ($slug === 'employees') {
            $search = new EmployeeSearch($request);

            return [$search->apply($query->reorder()), $search->activeFilterCount() > 0 || $search->text !== ''];
        }

        if ($slug === 'wellness-leaves' && $year) {
            return [$query->where('inclusive_date_from', '<=', "{$year}-12-31")->where('inclusive_date_to', '>=', "{$year}-01-01"), true];
        }

        if (in_array($slug, self::YEARLY, true) && $year) {
            return [$query->where('year', $year), true];
        }

        if ($slug === 'applications') {
            return $this->filteredApplications($request, $query);
        }

        return [$query, false];
    }

    /**
     * @param  Builder<Model>  $query
     * @return array{0: Builder<Model>, 1: bool}
     */
    private function filteredApplications(Request $request, Builder $query): array
    {
        $text = trim((string) $request->query('q'));
        $statuses = array_values(array_filter((array) $request->query('status')));
        $requirements = (string) $request->query('requirements');
        $station = $request->integer('station');

        $query
            ->when($text !== '', fn (Builder $any) => $any->where(fn (Builder $match) => $match
                ->where('applicant_name', 'like', "%{$text}%")
                ->orWhere('application_id', 'like', "%{$text}%")
                ->orWhere('position_applied', 'like', "%{$text}%")))
            ->when($statuses !== [], fn (Builder $any) => $any->whereIn('application_status', $statuses))
            ->when($requirements !== '', fn (Builder $any) => $any->where('requirements_status', $requirements))
            ->when($station > 0, fn (Builder $any) => $any->where('station_id', $station));

        return [$query, $text !== '' || $statuses !== [] || $requirements !== '' || $station > 0];
    }

    private function year(Request $request): ?int
    {
        $year = $request->integer('year');

        return $year >= 2000 && $year <= 2100 ? $year : null;
    }

    private function download(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ]);
    }
}
