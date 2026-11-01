<?php

namespace App\Http\Controllers;

use App\Enums\RemittanceKind;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\RemittanceSetting;
use App\Models\VoucherSetting;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

class RemittanceSettingsController extends Controller
{
    use RespondsToForms;

    public function edit(Request $request): View
    {
        $kind = RemittanceKind::tryFrom((string) $request->query('kind')) ?? RemittanceKind::Sss;
        $setting = RemittanceSetting::forKind($kind);

        Gate::authorize('update', $setting);

        return view('remittances.settings', [
            'kind' => $kind,
            'kinds' => RemittanceKind::cases(),
            'setting' => $setting,
            'voucher' => VoucherSetting::current(),
        ]);
    }

    public function update(Request $request, string $kind): RedirectResponse
    {
        $kind = RemittanceKind::tryFrom($kind) ?? abort(404);
        $setting = RemittanceSetting::forKind($kind);

        Gate::authorize('update', $setting);

        try {
            $amounts = [
                'default_amount_centavos' => Money::toCentavos($request->input('default_amount')),
                'default_employer_share_centavos' => Money::toCentavos($request->input('default_employer_share')),
                'default_compensation_centavos' => Money::toCentavos($request->input('default_compensation')),
            ];
        } catch (InvalidArgumentException $exception) {
            return $this->refused('A default amount '.$exception->getMessage().'.');
        }

        $percent = trim((string) $request->input('service_charge_percent', '0'));

        if (! is_numeric($percent) || (float) $percent < 0 || (float) $percent > 20) {
            return $this->refused('The service charge must be a percentage from 0 to 20.');
        }

        $setting->update([
            'title' => $this->text($request, 'title', 160) ?? $kind->defaults()['title'],
            'letterhead_lines' => mb_substr((string) $request->input('letterhead_lines'), 0, 600) ?: null,
            'employer_id' => $this->text($request, 'employer_id', 40),
            'employer_name' => $this->text($request, 'employer_name', 160),
            'employer_address' => $this->text($request, 'employer_address', 255),
            'employer_contact' => $this->text($request, 'employer_contact', 120),
            'form_code' => $this->text($request, 'form_code', 40),
            'membership_program' => $this->text($request, 'membership_program', 60),
            ...$amounts,
            'service_charge_basis_points' => (int) round((float) $percent * 100),
            'prepared_by_name' => $this->text($request, 'prepared_by_name', 120),
            'prepared_by_title' => $this->text($request, 'prepared_by_title', 120),
            'noted_by_name' => $this->text($request, 'noted_by_name', 120),
            'noted_by_title' => $this->text($request, 'noted_by_title', 120),
            'certification_text' => mb_substr((string) $request->input('certification_text'), 0, 1000) ?: null,
            'voucher_payee' => $this->text($request, 'voucher_payee', 160),
            'voucher_address' => $this->text($request, 'voucher_address', 255),
            'voucher_particulars' => mb_substr((string) $request->input('voucher_particulars'), 0, 1000) ?: null,
        ]);

        return $this->flash(redirect()->route('remittances.settings', ['kind' => $kind->value]), "{$kind->label()} template saved. New exports and vouchers use it right away.");
    }

    public function updateVoucher(Request $request): RedirectResponse
    {
        $voucher = VoucherSetting::current();

        Gate::authorize('update', $voucher);

        $voucher->update([
            'mode_of_payment' => $this->text($request, 'mode_of_payment', 40) ?? 'Check',
            'fund_cluster' => $this->text($request, 'fund_cluster', 60),
            'responsibility_center' => $this->text($request, 'responsibility_center', 60),
            'supervisor_name' => $this->text($request, 'supervisor_name', 120),
            'supervisor_title' => $this->text($request, 'supervisor_title', 120),
            'accountant_name' => $this->text($request, 'accountant_name', 120),
            'accountant_title' => $this->text($request, 'accountant_title', 120),
            'approver_name' => $this->text($request, 'approver_name', 120),
            'approver_title' => $this->text($request, 'approver_title', 120),
        ]);

        return $this->flash(redirect()->route('remittances.settings', ['kind' => $request->input('kind')]), 'Voucher officials saved.');
    }

    private function text(Request $request, string $name, int $max): ?string
    {
        return mb_substr(trim((string) $request->input($name)), 0, $max) ?: null;
    }
}
