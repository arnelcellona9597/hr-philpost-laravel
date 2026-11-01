<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipSetting;
use App\Models\SiteSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PayslipSettingsController extends Controller
{
    use RespondsToForms;

    private const SHOWN_FIELDS = [
        'employee_id' => 'Employee ID',
        'position' => 'Position',
        'station' => 'Station',
        'classification' => 'Classification',
        'tin' => 'TIN',
        'sss' => 'SSS',
        'philhealth' => 'PhilHealth',
        'pagibig' => 'Pag-IBIG',
    ];

    private const MAX_ITEMS = 30;

    private const MAX_LABEL = 40;

    public function edit(): View
    {
        $settings = PayslipSetting::current();

        Gate::authorize('update', $settings);

        $site = SiteSetting::current();
        $real = Payslip::query()->with('period')->latest('updated_at')->first();

        return view('payroll.settings', [
            'settings' => $settings,
            'shownFields' => self::SHOWN_FIELDS,
            'siteName' => $site->company_name,
            'companyName' => $settings->company_name ?: $site->company_name,
            'logoUrl' => $site->logo_id ? route('files.show', $site->logo_id, false) : null,
            'real' => $real,
            'slip' => $real ?? $this->sampleSlip(),
            'period' => $real?->period ?? $this->samplePeriod(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $settings = PayslipSetting::current();

        Gate::authorize('update', $settings);

        $earnings = $this->lines($request, 'earning_items');
        $deductions = $this->lines($request, 'deduction_items');

        if ($earnings === [] || $deductions === []) {
            return $this->refused('List at least one earning item and one deduction item.');
        }

        if (count($earnings) > self::MAX_ITEMS || count($deductions) > self::MAX_ITEMS) {
            return $this->refused('List at most '.self::MAX_ITEMS.' earning items and '.self::MAX_ITEMS.' deduction items.');
        }

        foreach ([...$earnings, ...$deductions] as $label) {
            if (mb_strlen($label) > self::MAX_LABEL) {
                return $this->refused("“{$label}” is longer than ".self::MAX_LABEL.' characters.');
            }
        }

        $settings->update([
            'title' => $this->text($request, 'title', 60) ?? 'PAYSLIP',
            'company_name' => $this->text($request, 'company_name', 160),
            'address_lines' => mb_substr((string) $request->input('address_lines'), 0, 600),
            'show_logo' => $request->boolean('show_logo'),
            'currency_symbol' => $this->text($request, 'currency_symbol', 5) ?? '₱',
            'earning_items' => $earnings,
            'deduction_items' => $deductions,
            'shown_fields' => collect(self::SHOWN_FIELDS)->map(fn (string $label, string $field): bool => $request->boolean("show_{$field}"))->all(),
            'prepared_by_name' => $this->text($request, 'prepared_by_name', 120),
            'prepared_by_title' => $this->text($request, 'prepared_by_title', 120),
            'certified_by_name' => $this->text($request, 'certified_by_name', 120),
            'certified_by_title' => $this->text($request, 'certified_by_title', 120),
            'footer_note' => mb_substr((string) $request->input('footer_note'), 0, 600),
            'paper_size' => in_array($request->input('paper_size'), ['A4', 'Letter', 'Half-Letter'], true) ? $request->input('paper_size') : 'A4',
        ]);

        return $this->flash(redirect()->route('payroll.settings'), 'Payslip template saved. New prints and PDFs use it right away.');
    }

    /**
     * @return list<string>
     */
    private function lines(Request $request, string $name): array
    {
        return array_values(array_unique(array_filter(array_map(trim(...), explode("\n", (string) $request->input($name))), fn (string $line): bool => $line !== '')));
    }

    private function text(Request $request, string $name, int $max): ?string
    {
        return mb_substr(trim((string) $request->input($name)), 0, $max) ?: null;
    }

    private function sampleSlip(): Payslip
    {
        return (new Payslip)->forceFill([
            'full_name' => 'Dela Cruz, Juan P.',
            'employee_code' => 'EMP-0001',
            'position' => 'Administrative Officer II',
            'station' => 'Main Office',
            'classification' => 'Regular',
            'tin' => '123-456-789-000',
            'sss' => '34-1234567-8',
            'philhealth' => '01-000000000-1',
            'pagibig' => '1210-0000-0001',
            'earnings' => [['label' => 'Basic Pay', 'amount_centavos' => 2_500_000], ['label' => 'PERA', 'amount_centavos' => 200_000]],
            'deductions' => [['label' => 'Withholding Tax', 'amount_centavos' => 150_000], ['label' => 'PhilHealth', 'amount_centavos' => 62_500]],
            'gross_pay_centavos' => 2_700_000,
            'total_deductions_centavos' => 212_500,
            'net_pay_centavos' => 2_487_500,
        ]);
    }

    private function samplePeriod(): PayrollPeriod
    {
        return new PayrollPeriod(['name' => 'October 2026 — 1st half', 'period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'pay_date' => '2026-10-20']);
    }
}
