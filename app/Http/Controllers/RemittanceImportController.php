<?php

namespace App\Http\Controllers;

use App\Enums\RemittanceKind;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Remittance;
use App\Services\PendingImports;
use App\Services\RemittancesWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RemittanceImportController extends Controller
{
    use RespondsToForms;

    private const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Check an uploaded workbook. Nothing is saved until the person confirms the result.
     */
    public function preview(Request $request, RemittancesWorkbook $workbook, PendingImports $imports): View|RedirectResponse
    {
        Gate::authorize('create', Remittance::class);

        $kind = RemittanceKind::tryFrom((string) $request->input('kind')) ?? RemittanceKind::Sss;
        $file = $request->file('file');
        $index = redirect()->route('remittances.index', ['kind' => $kind->value]);

        if (! $file instanceof UploadedFile || ! $file->isValid() || ! preg_match('/\.xlsx$/i', $file->getClientOriginalName())) {
            return $this->flash($index, 'Upload an .xlsx workbook.', 'error');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return $this->flash($index, 'The file is larger than 10 MB.', 'error');
        }

        [$year, $month] = $this->fallback($request);
        $plan = $workbook->analyze($file->getRealPath(), $kind, $year, $month);
        $token = $imports->stage((string) file_get_contents($file->getRealPath()), $request->user()->id, $file->getClientOriginalName(), $this->hint($kind));

        return view('remittances.import', ['plan' => $plan, 'token' => $token, 'fileName' => $file->getClientOriginalName(), 'fallbackYear' => $year, 'fallbackMonth' => $month]);
    }

    public function commit(Request $request, RemittancesWorkbook $workbook, PendingImports $imports): View|RedirectResponse
    {
        Gate::authorize('create', Remittance::class);

        $kind = RemittanceKind::tryFrom((string) $request->input('kind')) ?? RemittanceKind::Sss;
        $token = (string) $request->input('token');
        $staged = $imports->claim($token, $request->user()->id, $this->hint($kind));

        if ($staged === null) {
            return $this->flash(redirect()->route('remittances.index', ['kind' => $kind->value]), 'This upload expired or was already imported. Upload the file again.', 'error');
        }

        [$year, $month] = $this->fallback($request);
        $fileName = $staged['meta']['file_name'];
        $plan = $workbook->analyze($staged['path'], $kind, $year, $month);

        if ($plan['errors'] !== []) {
            $imports->release($token);

            return view('remittances.import', ['plan' => $plan, 'token' => $token, 'fileName' => $fileName, 'fallbackYear' => $year, 'fallbackMonth' => $month]);
        }

        $result = $workbook->commit($plan, $request->user(), $fileName);
        $imports->finish($token);

        return $this->flash(redirect()->route('remittances.index', ['kind' => $kind->value]), "Imported {$fileName}: {$result['created']} new month(s), {$result['replaced']} replaced, {$result['people']} people.");
    }

    private function hint(RemittanceKind $kind): string
    {
        return 'remit-'.$kind->value;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function fallback(Request $request): array
    {
        $year = (int) $request->input('year');
        $month = (int) $request->input('month');

        return $year >= 2000 && $year <= 2100 && $month >= 1 && $month <= 12 ? [$year, $month] : [null, null];
    }
}
