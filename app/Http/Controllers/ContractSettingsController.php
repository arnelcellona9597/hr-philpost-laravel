<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\ContractSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ContractSettingsController extends Controller
{
    use RespondsToForms;

    public function edit(): View
    {
        $settings = ContractSetting::current();

        Gate::authorize('update', $settings);

        return view('contracts.settings', ['settings' => $settings, 'officials' => ContractSetting::OFFICIALS]);
    }

    public function update(Request $request): RedirectResponse
    {
        $settings = ContractSetting::current();

        Gate::authorize('update', $settings);

        $premium = trim((string) $request->input('premium_percent', '0'));

        if (! ctype_digit($premium) || (int) $premium > 100) {
            return $this->refused('The premium must be a whole number from 0 to 100.');
        }

        $values = ['premium_percent' => (int) $premium];

        foreach (array_keys(ContractSetting::OFFICIALS) as $key) {
            $values["{$key}_name"] = $this->text($request, "{$key}_name", 120);
            $values["{$key}_title"] = $this->text($request, "{$key}_title", 160);
        }

        $settings->update([
            ...$values,
            'default_place_of_service' => $this->text($request, 'default_place_of_service', 160),
            'wage_order' => $this->text($request, 'wage_order', 255),
        ]);

        return $this->flash(redirect()->route('contracts.settings'), 'Contract settings saved. New contracts use them; issued ones keep theirs.');
    }

    private function text(Request $request, string $name, int $max): ?string
    {
        return mb_substr(trim((string) $request->input($name)), 0, $max) ?: null;
    }
}
