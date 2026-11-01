<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Payslip;
use App\Models\PayslipSetting;
use App\Models\SiteSetting;
use App\Services\PayrollService;
use App\Services\PdfRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PayslipController extends Controller
{
    use RespondsToForms;

    public function show(Payslip $payslip, PayrollService $payroll): View
    {
        Gate::authorize('view', $payslip);

        $labels = $payroll->itemLabels();
        $notInTemplate = fn (array $items, array $list): array => array_values(array_filter($items, fn (array $item): bool => ! in_array($item['label'], $list, true)));

        return view('payroll.payslip', [
            'slip' => $payslip->load(['period', 'employee']),
            'settings' => PayslipSetting::current(),
            'labels' => $labels,
            'extra' => [...$notInTemplate($payslip->earnings, $labels['earnings']), ...$notInTemplate($payslip->deductions, $labels['deductions'])],
        ]);
    }

    public function update(Request $request, Payslip $payslip, PayrollService $payroll): RedirectResponse
    {
        Gate::authorize('update', $payslip);

        $labels = $payroll->itemLabels();
        $values = [];

        foreach (['e' => $labels['earnings'], 'd' => $labels['deductions']] as $kind => $list) {
            foreach ($list as $index => $label) {
                $values["{$kind}:{$label}"] = (string) $request->input("{$kind}_{$index}");
            }
        }

        $result = $payroll->saveRows($payslip->period, [['id' => $payslip->id, 'values' => $values, 'remarks' => trim((string) $request->input('remarks'))]]);

        if ($result['errors'] !== []) {
            return back()->withInput()->withErrors(['form' => implode(' ', array_column($result['errors'], 'message'))]);
        }

        return $this->flash(redirect()->route('payroll.payslips.show', $payslip), 'Payslip saved.');
    }

    public function destroy(Payslip $payslip, PayrollService $payroll): RedirectResponse
    {
        Gate::authorize('delete', $payslip);

        try {
            $payroll->deletePayslip($payslip);
        } catch (UserFacingException $exception) {
            return $this->flash(redirect()->route('payroll.payslips.show', $payslip), $exception->getMessage(), 'error');
        }

        return $this->flash(redirect()->route('payroll.show', $payslip->period_id), 'Payslip deleted.');
    }

    public function resend(Request $request, Payslip $payslip, PayrollService $payroll): RedirectResponse
    {
        Gate::authorize('update', $payslip);

        $redirect = redirect()->route('payroll.payslips.show', $payslip);

        try {
            $sent = $payroll->resend($payslip, $request->user());
        } catch (UserFacingException $exception) {
            return $this->flash($redirect, $exception->getMessage(), 'error');
        }

        return $sent
            ? $this->flash($redirect, 'The payslip is being emailed.')
            : $this->flash($redirect, 'This employee has no email address on file.', 'error');
    }

    public function print(Payslip $payslip): View
    {
        Gate::authorize('view', $payslip);

        $site = SiteSetting::current();
        $settings = PayslipSetting::current();

        return view('payroll.payslip-print', [
            'slip' => $payslip,
            'period' => $payslip->period,
            'settings' => $settings,
            'companyName' => $settings->company_name ?: $site->company_name,
            'logoUrl' => $site->logo_id ? route('files.show', $site->logo_id, false) : null,
        ]);
    }

    public function pdf(Payslip $payslip, PdfRenderer $pdf): Response
    {
        Gate::authorize('view', $payslip);

        return response($pdf->payslip($payslip), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->payslipFilename($payslip).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
