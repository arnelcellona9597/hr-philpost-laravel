<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\PayrollPeriod;
use App\Services\PayrollWorkbook;
use App\Services\PendingImports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PayrollImportController extends Controller
{
    use RespondsToForms;

    private const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Check an uploaded workbook. Nothing is saved until the person confirms the result.
     */
    public function store(Request $request, PayrollPeriod $period, PayrollWorkbook $workbook, PendingImports $imports): View|RedirectResponse
    {
        Gate::authorize('update', $period);

        $file = $request->file('file');

        if (! $file instanceof UploadedFile || ! $file->isValid() || ! preg_match('/\.xlsx$/i', $file->getClientOriginalName())) {
            return $this->flash(redirect()->route('payroll.show', $period), 'Upload an .xlsx workbook.', 'error');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return $this->flash(redirect()->route('payroll.show', $period), 'The file is larger than 10 MB.', 'error');
        }

        $plan = $workbook->analyze($period, $file->getRealPath());
        $token = $imports->stage((string) file_get_contents($file->getRealPath()), $request->user()->id, $file->getClientOriginalName(), $this->hint($period));

        return view('payroll.import', ['period' => $period, 'plan' => $plan, 'token' => $token]);
    }

    public function commit(Request $request, PayrollPeriod $period, PayrollWorkbook $workbook, PendingImports $imports): View|RedirectResponse
    {
        Gate::authorize('update', $period);

        $token = (string) $request->input('token');
        $staged = $imports->claim($token, $request->user()->id, $this->hint($period));

        if ($staged === null) {
            return $this->flash(redirect()->route('payroll.show', $period), 'This upload expired or was already imported. Upload the file again.', 'error');
        }

        $plan = $workbook->analyze($period, $staged['path']);
        $result = $plan['errors'] === [] ? $workbook->commit($period, $plan) : ['errors' => [], 'updated' => 0, 'created' => 0];

        if ($plan['errors'] !== [] || $result['errors'] !== []) {
            $imports->release($token);
            $plan['errors'] = [...$plan['errors'], ...$result['errors']];

            return view('payroll.import', ['period' => $period, 'plan' => $plan, 'token' => $token]);
        }

        $imports->finish($token);

        return $this->flash(redirect()->route('payroll.show', $period), "Imported {$staged['meta']['file_name']}: {$result['updated']} payslip(s) updated, {$result['created']} created.");
    }

    private function hint(PayrollPeriod $period): string
    {
        return "payroll:{$period->id}";
    }
}
