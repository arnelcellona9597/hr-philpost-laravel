<?php

namespace App\Http\Controllers;

use App\Enums\HolidayType;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Holiday;
use App\Models\LeaveSetting;
use App\Support\HrDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LeaveSettingsController extends Controller
{
    use RespondsToForms;

    public function edit(Request $request): View
    {
        Gate::authorize('view', LeaveSetting::class);

        $year = $request->integer('year') ?: (int) substr(HrDate::today(), 0, 4);

        return view('leave.settings', [
            'settings' => LeaveSetting::current(),
            'holidays' => Holiday::query()->whereYear('date', $year)->orderBy('date')->limit(200)->get(),
            'year' => $year,
        ]);
    }

    public function updateAllowance(Request $request): RedirectResponse
    {
        Gate::authorize('update', LeaveSetting::class);

        $data = $request->validate(
            ['annual_allowance' => ['required', 'integer', 'min:0', 'max:60']],
            ['annual_allowance.*' => 'Allowance must be a whole number of days from 0 to 60.'],
        );

        $overrides = [];
        $days = (array) $request->input('override_days', []);

        foreach ((array) $request->input('override_year', []) as $index => $year) {
            $allowance = $days[$index] ?? null;

            if (blank($year) || blank($allowance)) {
                continue;
            }

            if (! ctype_digit((string) $year) || (int) $year < 2000 || (int) $year > 2100 || ! ctype_digit((string) $allowance) || (int) $allowance > 60) {
                return $this->refused('Each override needs a year from 2000 to 2100 and 0 to 60 days.');
            }

            if (isset($overrides[(int) $year])) {
                return $this->refused('Each year can only have one override.');
            }

            $overrides[(int) $year] = ['year' => (int) $year, 'allowance' => (int) $allowance];
        }

        LeaveSetting::current()->update(['annual_allowance' => (int) $data['annual_allowance'], 'year_overrides' => array_values($overrides)]);

        return $this->flash(back(), 'Wellness leave allowance saved.');
    }

    public function storeHoliday(Request $request): RedirectResponse
    {
        Gate::authorize('create', Holiday::class);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('holidays', 'date')],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(HolidayType::class)],
        ], ['date.unique' => 'That date is already in the holiday list.', 'date.*' => 'Pick a valid holiday date.']);

        Holiday::query()->create($data);

        return $this->flash(back(), 'Holiday added. It applies to leave filed from now on.');
    }

    public function destroyHoliday(Holiday $holiday): RedirectResponse
    {
        Gate::authorize('delete', $holiday);

        $holiday->delete();

        return $this->flash(back(), 'Holiday removed.');
    }
}
