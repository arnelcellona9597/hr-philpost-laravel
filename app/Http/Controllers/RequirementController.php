<?php

namespace App\Http\Controllers;

use App\Enums\IpcrPeriod;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\ComplianceRecord;
use App\Models\Employee;
use App\Models\IpcrRating;
use App\Models\RequirementDeadlineSetting;
use App\Services\RequirementService;
use App\Support\Choices;
use App\Support\Deadlines;
use App\Support\HrDate;
use App\Support\ImportModules;
use App\Support\RecordForm;
use App\Support\RecordRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RequirementController extends Controller
{
    use RespondsToForms;

    private const STATUSES = ['on-time', 'late', 'pending', 'overdue', 'submitted'];

    private const SEARCHABLE = ['last_name', 'first_name', 'middle_name', 'station', 'job_title', 'tin', 'remarks'];

    public function overview(Request $request, RequirementService $requirements): View
    {
        Gate::authorize('viewAny', ComplianceRecord::modelFor('itr'));

        $year = $this->year($request);

        $cards = array_map(function (string $route) use ($requirements, $year): array {
            $model = ComplianceRecord::modelFor($route);

            return [
                'route' => $route,
                'title' => $this->module($route)['title'],
                'stats' => $requirements->compliance($model, $year),
                'timely' => $requirements->timeliness($model, $year),
            ];
        }, array_keys(ImportModules::COMPLIANCE_ROUTES));

        return view('requirements.overview', ['cards' => $cards, 'year' => $year]);
    }

    public function index(Request $request, string $type, RequirementService $requirements): View
    {
        $model = ComplianceRecord::modelFor($type);
        Gate::authorize('viewAny', $model);

        $module = $this->module($type);
        $year = $this->year($request);
        $view = $request->query('view') === 'missing' ? 'missing' : 'records';
        $text = Str::limit(trim((string) $request->query('q')), 100, '');
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : null;
        $settings = RequirementDeadlineSetting::current()->toArray();
        $stats = $requirements->compliance($model, $year);
        $timely = $requirements->timeliness($model, $year);

        $records = null;
        $missing = null;

        if ($view === 'records') {
            $table = (new $model)->getTable();
            $searchable = array_values(array_intersect(self::SEARCHABLE, Schema::getColumnListing($table)));

            $query = $model::query()
                ->with('employee')
                ->where('year', $year)
                ->when($text !== '', function (Builder $records) use ($text, $searchable): void {
                    $like = '%'.addcslashes($text, '%_\\').'%';

                    $records->where(function (Builder $any) use ($like, $searchable): void {
                        foreach ($searchable as $column) {
                            $any->orWhere($column, 'like', $like);
                        }
                    });
                });

            if ($status !== null) {
                $requirements->filterByStatus($query, $status, $timely['deadlines']);
            }

            $records = $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate(50)->withQueryString();
        } else {
            $missing = Employee::query()->with('station')->whereIn('id', $stats['missingIds'] ?: [0])->orderBy('full_name')->paginate(50)->withQueryString();
        }

        return view('requirements.index', [
            'type' => $type,
            'module' => $module,
            'columns' => array_values(array_filter($module['fields'], fn (array $field): bool => ! in_array($field['name'], ['employee', 'year', 'deadline'], true))),
            'isIpcr' => $model === IpcrRating::class,
            'slug' => $model::SLUG,
            'year' => $year,
            'view' => $view,
            'text' => $text,
            'status' => $status,
            'stats' => $stats,
            'counts' => $timely['counts'],
            'overdueIds' => $timely['ids']['overdue'],
            'deadlines' => $timely['deadlines'],
            'settings' => $settings,
            'records' => $records,
            'missing' => $missing,
            'hasSearchableWork' => Schema::hasColumn((new $model)->getTable(), 'station'),
        ]);
    }

    public function create(Request $request, string $type): View
    {
        $model = ComplianceRecord::modelFor($type);
        Gate::authorize('create', $model);

        $year = $this->year($request);

        return view('requirements.create', [
            'type' => $type,
            'module' => $this->module($type),
            'year' => $year,
            'fields' => $this->fields($type, $year),
            'values' => ['year' => (string) $year, 'employee' => (string) $request->query('employee')],
            'employees' => Choices::employees(),
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        $model = ComplianceRecord::modelFor($type);
        Gate::authorize('create', $model);

        return $this->save($request, $type, new $model);
    }

    public function edit(string $type, int $record): View
    {
        $model = ComplianceRecord::modelFor($type);
        $found = $model::query()->findOrFail($record);
        Gate::authorize('update', $found);

        $period = $found->ratingPeriodValue();
        $fields = $this->fields($type, $found->year, $period);

        return view('requirements.edit', [
            'type' => $type,
            'module' => $this->module($type),
            'record' => $found,
            'fields' => $fields,
            'values' => RecordForm::values($found, $fields),
            'employees' => Choices::employees(),
            'timely' => Deadlines::timeliness($found->date_submitted, $found->deadline),
        ]);
    }

    public function update(Request $request, string $type, int $record): RedirectResponse
    {
        $found = ComplianceRecord::modelFor($type)::query()->findOrFail($record);
        Gate::authorize('update', $found);

        return $this->save($request, $type, $found);
    }

    public function destroy(string $type, int $record): RedirectResponse
    {
        $found = ComplianceRecord::modelFor($type)::query()->findOrFail($record);
        Gate::authorize('delete', $found);

        $found->delete();

        return $this->flash(redirect()->route('requirements.index', [$type, 'year' => $found->year]), 'The record was deleted.');
    }

    /**
     * Create blank rows for every active employee who has no record yet this year.
     */
    public function generate(Request $request, string $type, RequirementService $requirements): RedirectResponse
    {
        $model = ComplianceRecord::modelFor($type);
        Gate::authorize('create', $model);

        $year = $this->year($request);
        $period = $model === IpcrRating::class ? IpcrPeriod::tryFrom((string) $request->input('rating_period'))?->value : null;
        $redirect = redirect()->route('requirements.index', [$type, 'year' => $year]);

        if ($model === IpcrRating::class && $period === null) {
            return $this->flash($redirect, 'Choose the IPCR rating period first.', 'error');
        }

        $created = $requirements->generateRows($model, $year, $period);

        return $this->flash($redirect, $created > 0
            ? "Added {$created} row".($created === 1 ? '' : 's')." for {$year}. Fill in the dates as documents arrive."
            : 'Every active employee already has a row.');
    }

    /**
     * Change or reset this year's deadline straight from the list.
     */
    public function updateDeadline(Request $request, string $type): RedirectResponse
    {
        $model = ComplianceRecord::modelFor($type);
        Gate::authorize('update', RequirementDeadlineSetting::class);

        $year = $this->year($request);
        $isIpcr = $model === IpcrRating::class;
        $period = $isIpcr ? IpcrPeriod::tryFrom((string) $request->input('period'))?->value : null;
        $date = (string) $request->input('deadline');
        $reset = $request->boolean('reset');

        if ($isIpcr && $period === null) {
            return $this->flash(back(), 'Choose the IPCR rating period.', 'error');
        }

        if (! $reset && ! HrDate::isValid($date)) {
            return $this->flash(back(), 'Pick the new deadline date.', 'error');
        }

        $settings = RequirementDeadlineSetting::current();
        $others = array_values(array_filter($settings->overrides ?? [], fn (array $override): bool => ! (
            $override['requirement'] === $model::SLUG && (int) $override['year'] === $year && (! $isIpcr || ($override['period'] ?? null) === $period)
        )));

        $settings->update(['overrides' => $reset ? $others : [...$others, ['requirement' => $model::SLUG, 'period' => $period, 'year' => $year, 'deadline' => $date]]]);

        $label = $year.($period ? " ({$period})" : '');

        return $this->flash(back(), $reset ? "The {$label} deadline is back to the usual date." : "Deadline for {$label} set to ".HrDate::format($date).'.');
    }

    private function save(Request $request, string $type, ComplianceRecord $record): RedirectResponse
    {
        $fields = $this->fields($type, $record->year ?? $this->year($request));
        ['data' => $data, 'errors' => $errors] = RecordForm::parse($request, $fields, $record->getTable(), record: $record);

        $errors = [...$errors, ...RecordRules::errors($record::SLUG, $data)];

        if (isset($data['employee_id']) && ! Employee::query()->whereKey($data['employee_id'])->exists()) {
            $errors['employee'] = 'Select a valid employee';
        }

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $wasNew = ! $record->exists;

        try {
            $record->fill($data)->save();
        } catch (UniqueConstraintViolationException) {
            return $this->refused('This employee already has a '.$record::SINGULAR." record for {$record->year}.");
        }

        return $this->flash(
            redirect()->route('requirements.index', [$type, 'year' => $record->year]),
            $wasNew ? $this->module($type)['title']." record for {$record->first_name} {$record->last_name} ({$record->year}) was saved." : 'Record updated.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function module(string $type): array
    {
        return ImportModules::find(ImportModules::COMPLIANCE_ROUTES[$type]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(string $type, int $year, ?string $period = null): array
    {
        $module = $this->module($type);
        $help = $this->deadlineHelp($module['slug'], $year, $period);

        return array_map(fn (array $field): array => match ($field['name']) {
            'employee' => ['label' => 'Employee'] + $field,
            'date_submitted' => ['help' => $help] + $field,
            default => $field,
        }, $module['fields']);
    }

    /**
     * The deadline that decides On time / Late for this record.
     */
    private function deadlineHelp(string $slug, int $year, ?string $period): string
    {
        $settings = RequirementDeadlineSetting::current()->toArray();
        $periods = $slug === IpcrRating::SLUG && $period === null ? array_column(IpcrPeriod::cases(), 'value') : [$period];

        $parts = array_filter(array_map(function (?string $current) use ($settings, $slug, $year, $periods): ?string {
            $due = Deadlines::for($settings, $slug, $year, $current);

            return $due ? ($current && count($periods) > 1 ? "{$current}: " : '').HrDate::format($due) : null;
        }, $periods));

        if ($parts === []) {
            return "No deadline is set for {$year}, so this record can't be marked on time or late.";
        }

        return "Deadline for {$year}".($period ? " ({$period})" : '').': '.implode(' · ', $parts).'. On or before it is on time; after it is late.';
    }

    private function year(Request $request): int
    {
        return min(2100, max(2000, $request->integer('year') ?: (int) substr(HrDate::today(), 0, 4)));
    }
}
