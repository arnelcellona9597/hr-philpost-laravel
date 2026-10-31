<?php

namespace App\Http\Controllers;

use App\Enums\IpcrPeriod;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\IpcrRating;
use App\Models\RequirementDeadlineSetting;
use App\Support\Deadlines;
use App\Support\HrDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RequirementDeadlineController extends Controller
{
    use RespondsToForms;

    public const NAMES = [
        'itr-submissions' => 'Income Tax Return (ITR)',
        'sworn-declarations' => 'Sworn Declaration',
        'pds-submissions' => 'Personal Data Sheet (PDS)',
        'ipcr-ratings' => 'IPCR',
    ];

    public function edit(): View
    {
        Gate::authorize('view', RequirementDeadlineSetting::class);

        $settings = RequirementDeadlineSetting::current();
        $overrides = collect($settings->overrides ?? [])->sortBy([['year', 'desc'], ['requirement', 'asc']])->values()->all();

        return view('requirements.deadlines', [
            'settings' => $settings->toArray(),
            'rows' => [...$overrides, ['requirement' => '', 'period' => null, 'year' => null, 'deadline' => null]],
            'thisYear' => (int) substr(HrDate::today(), 0, 4),
        ]);
    }

    public function updateUsual(Request $request): RedirectResponse
    {
        Gate::authorize('update', RequirementDeadlineSetting::class);

        $data = [];

        foreach (Deadlines::RULES as $rule) {
            $month = $request->integer("{$rule['key']}-month") ?: null;
            $day = $request->integer("{$rule['key']}-day") ?: null;

            if (($month === null) !== ($day === null)) {
                return $this->refused("{$rule['label']}: choose both the month and the day, or leave both empty.");
            }

            if ($month !== null && ! Deadlines::isValidMonthDay($month, $day)) {
                return $this->refused("{$rule['label']}: {$month}/{$day} is not a valid date.");
            }

            $data[$rule['key']] = ['month' => $month, 'day' => $day, 'next_year' => $request->boolean("{$rule['key']}-next")];
        }

        RequirementDeadlineSetting::current()->update($data);

        return $this->flash(back(), 'Usual deadlines saved. On time / late is recalculated for every year that uses them.');
    }

    public function updateOverrides(Request $request): RedirectResponse
    {
        Gate::authorize('update', RequirementDeadlineSetting::class);

        $periods = (array) $request->input('period', []);
        $years = (array) $request->input('year', []);
        $dates = (array) $request->input('deadline', []);
        $overrides = [];
        $seen = [];

        foreach ((array) $request->input('requirement', []) as $index => $requirement) {
            $year = trim((string) ($years[$index] ?? ''));
            $date = (string) ($dates[$index] ?? '');

            if ($year === '' && $date === '') {
                continue;
            }

            $name = self::NAMES[$requirement] ?? 'Requirement';

            if (! isset(self::NAMES[$requirement])) {
                return $this->refused('Choose a requirement for every row.');
            }

            if (! ctype_digit($year) || (int) $year < 2000 || (int) $year > 2100) {
                return $this->refused("{$name}: enter the year (2000–2100).");
            }

            if (! HrDate::isValid($date)) {
                return $this->refused("{$name} {$year}: pick the deadline date.");
            }

            $isIpcr = $requirement === IpcrRating::SLUG;
            $period = $isIpcr ? IpcrPeriod::tryFrom((string) ($periods[$index] ?? ''))?->value : null;

            if ($isIpcr && $period === null) {
                return $this->refused("{$name} {$year}: choose the rating period.");
            }

            $key = "{$requirement}|{$period}|{$year}";

            if (isset($seen[$key])) {
                return $this->refused($name.($period ? " ({$period})" : '')." {$year} has more than one deadline.");
            }

            $seen[$key] = true;
            $overrides[] = ['requirement' => $requirement, 'period' => $period, 'year' => (int) $year, 'deadline' => $date];
        }

        RequirementDeadlineSetting::current()->update(['overrides' => $overrides]);

        return $this->flash(back(), 'Year-specific deadlines saved.');
    }
}
