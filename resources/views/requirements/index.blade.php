@php
    use App\Enums\IpcrPeriod;
    use App\Models\Employee;
    use App\Support\Badge;
    use App\Support\Deadlines;
    use App\Support\HrDate;
    use App\Support\Names;

    $today = HrDate::today();
    $title = $module['title'];
    $hasDeadline = collect($deadlines)->contains(fn (array $deadline): bool => $deadline['deadline'] !== null);
    $singleDeadline = $isIpcr ? null : ($deadlines[0]['deadline'] ?? null);
    $tab = fn (string $name): string => route('requirements.index', [$type, 'year' => $year, ...($name === 'missing' ? ['view' => 'missing'] : [])]);
    $statusLink = fn (string $name): string => route('requirements.index', [$type, 'year' => $year, 'status' => $name, ...($text !== '' ? ['q' => $text] : [])]);
    $statusOptions = ['on-time' => 'On time', 'late' => 'Late', 'pending' => 'Pending', 'overdue' => 'Overdue', 'submitted' => 'Submitted (no deadline)'];
    $show = function ($record, array $column): string {
        $value = $record->getAttribute($column['name']);

        return match (true) {
            $value === null || $value === '' => '',
            $column['kind'] === 'date' => HrDate::format($value),
            $value instanceof BackedEnum => $value->value,
            in_array($column['name'], Employee::NAME_FIELDS, true) => Names::title($value),
            default => (string) $value,
        };
    };
    $statusOf = fn ($record): array => Deadlines::timeliness($record->date_submitted, Deadlines::for($settings, $slug, $year, $record->ratingPeriodValue()));
    $reminder = http_build_query(array_filter([
        'template' => 'requirement-reminder',
        'employees' => implode(',', $stats['missingIds']),
        'ctx_requirement' => $title,
        'ctx_year' => $year,
        'ctx_deadline' => $singleDeadline ? HrDate::format($singleDeadline) : null,
    ]));
@endphp

<x-layouts.app
    :title="$title"
    :description="'Records for '.$year.'. Compliance counts active employees with a Date Submitted; on time or late compares it with the deadline.'"
    :back="['href' => route('requirements.overview', ['year' => $year]), 'label' => 'Annual requirements']"
>
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('requirements.deadlines') }}"><x-icon name="calendar" /> Deadlines</a>
        <a class="btn-secondary" href="{{ url("/data/export?module={$slug}&year={$year}") }}"><x-icon name="download" /> Export {{ $year }}</a>
        <a class="btn-primary" href="{{ route('requirements.create', [$type, 'year' => $year]) }}"><x-icon name="plus" /> Add record</a>
    </x-slot:actions>

    <div class="mb-4 flex items-center gap-2">
        <a class="btn-secondary btn-sm" href="{{ route('requirements.index', [$type, 'year' => $year - 1]) }}" aria-label="Previous year">←</a>
        <span class="text-lg font-semibold tabular">{{ $year }}</span>
        <a class="btn-secondary btn-sm" href="{{ route('requirements.index', [$type, 'year' => $year + 1]) }}" aria-label="Next year">→</a>
    </div>

    <section class="card mb-4 divide-y divide-line" aria-label="Deadline for {{ $year }}" data-deadline-banner>
        @foreach ($deadlines as $deadline)
            @php($period = $deadline['period'])
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                <p class="flex min-w-0 flex-1 items-center gap-2 text-sm">
                    <x-icon name="calendar" class="size-4 shrink-0 text-muted" />
                    <span>
                        Deadline for {{ $year }}{{ $period ? " ({$period})" : '' }}:
                        @if ($deadline['deadline'])
                            <strong class="tabular">{{ HrDate::format($deadline['deadline']) }}</strong>
                        @else
                            <span class="text-muted">not set</span>
                        @endif
                        @if ($deadline['source'] === 'override')
                            <span class="badge-primary ml-1.5">changed for {{ $year }}</span>
                        @elseif ($deadline['source'] === 'usual')
                            <span class="ml-1.5 text-xs text-muted">(usual deadline)</span>
                        @endif
                        @if ($deadline['deadline'] && $today > $deadline['deadline'])
                            <span class="ml-1.5 text-xs text-muted">· passed</span>
                        @endif
                    </span>
                </p>
                <details class="relative">
                    <summary class="btn-ghost btn-sm cursor-pointer list-none">{{ $deadline['deadline'] ? "Change for {$year}" : "Set for {$year}" }}</summary>
                    <form method="post" action="{{ route('requirements.deadline', [$type, 'year' => $year]) }}" class="absolute right-0 z-20 mt-2 flex w-72 flex-col gap-2 rounded-xl border border-line bg-surface p-3 shadow-pop">
                        @csrf
                        @method('PUT')
                        @if ($period)
                            <input type="hidden" name="period" value="{{ $period }}" />
                        @endif
                        <label class="label !mb-0 text-xs" for="dl-{{ $period ?? 'all' }}">New deadline for {{ $year }}{{ $period ? " ({$period})" : '' }}</label>
                        <input class="input" type="date" id="dl-{{ $period ?? 'all' }}" name="deadline" required value="{{ $deadline['deadline'] }}" />
                        <p class="field-help !mt-0">Only {{ $year }} changes; other years keep the usual deadline.</p>
                        <button class="btn-primary btn-sm">Save deadline</button>
                    </form>
                </details>
                @if ($deadline['source'] === 'override')
                    <form method="post" action="{{ route('requirements.deadline', [$type, 'year' => $year]) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="reset" value="1" />
                        @if ($period)
                            <input type="hidden" name="period" value="{{ $period }}" />
                        @endif
                        <button class="btn-ghost btn-sm">Use usual date</button>
                    </form>
                @endif
            </div>
        @endforeach
    </section>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Active employees" :value="$stats['active']" />
        <x-stat-card
            label="Complied"
            :value="$stats['complied']"
            tone="success"
            :note="collect([$stats['active'] ? round($stats['complied'] / $stats['active'] * 100).'%' : null, $hasDeadline ? $counts['on-time'].' on time' : null])->filter()->implode(' · ') ?: null"
            :href="$hasDeadline ? $statusLink('on-time') : null"
        />
        <x-stat-card
            label="Late submissions"
            :value="$hasDeadline ? $counts['late'] : '—'"
            :tone="$counts['late'] ? 'danger' : 'neutral'"
            :note="$hasDeadline ? 'Submitted after the deadline' : 'Set a deadline to track this'"
            :href="$hasDeadline ? $statusLink('late') : route('requirements.deadlines')"
        />
        <x-stat-card label="Missing" :value="$stats['missing']" :tone="$stats['missing'] ? 'warning' : 'success'" :note="$counts['overdue'] ? $counts['overdue'].' overdue' : null" :href="$tab('missing')" />
    </div>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <div class="flex rounded-lg border border-line bg-surface p-0.5 text-sm">
            <a href="{{ $tab('records') }}" @class(['rounded-md px-3 py-1.5', 'bg-primary-soft font-medium text-primary-ink' => $view === 'records', 'text-fg-2' => $view !== 'records'])>Records</a>
            <a href="{{ $tab('missing') }}" @class(['rounded-md px-3 py-1.5', 'bg-primary-soft font-medium text-primary-ink' => $view === 'missing', 'text-fg-2' => $view !== 'missing'])>Not yet complied ({{ $stats['missing'] }})</a>
        </div>
        @if ($stats['missing'] > 0)
            <a class="btn-secondary" href="{{ url("/messages/new?{$reminder}") }}"><x-icon name="mail" /> Email reminder to all {{ $stats['missing'] }} missing</a>
        @endif
        <form method="post" action="{{ route('requirements.generate', [$type, 'year' => $year]) }}" class="flex items-center gap-2" data-confirm="Add a blank {{ $year }} row for every active employee who has none?">
            @csrf
            @if ($isIpcr)
                <select class="input w-auto" name="rating_period" aria-label="Rating period" required>
                    @foreach (IpcrPeriod::cases() as $period)
                        <option>{{ $period->value }}</option>
                    @endforeach
                </select>
            @endif
            <button class="btn-secondary">Generate rows for all active employees</button>
        </form>
    </div>

    @if ($view === 'records')
        <form method="get" class="mt-4 flex flex-wrap gap-2">
            <input type="hidden" name="year" value="{{ $year }}" />
            <input class="input min-w-0 max-w-md flex-1" type="search" name="q" value="{{ $text }}" placeholder="{{ $hasSearchableWork ? 'Search name, job title, station, remarks' : 'Search name or remarks' }}" aria-label="Search" />
            <select class="input w-auto" name="status" aria-label="Timeliness" data-autosubmit>
                <option value="">Any status</option>
                @foreach ($statusOptions as $value => $label)
                    <option value="{{ $value }}" @selected($value === $status)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="btn-secondary">Search</button>
            @if ($text !== '' || $status)
                <a class="btn-ghost" href="{{ $tab('records') }}">Clear</a>
            @endif
        </form>
    @endif

    <div class="card mt-4 overflow-hidden">
        @if ($records !== null)
            @if ($records->total() === 0)
                <x-empty-state
                    :title="$status || $text !== '' ? 'No records match.' : 'No '.$title.' records for '.$year"
                    :text="$status || $text !== '' ? 'Try another status or search.' : 'Add records one by one, generate rows for all active employees, or import from Excel.'"
                />
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Employee ID</th>
                                @foreach ($columns as $column)
                                    <th @class(['text-right' => $column['kind'] === 'number'])>{{ $column['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($records as $record)
                                @php($timely = $statusOf($record))
                                <tr>
                                    <td class="whitespace-nowrap font-mono text-xs"><a class="hover:text-primary-ink" href="{{ route('requirements.edit', [$type, $record->id]) }}">{{ $record->employee?->employee_id }}</a></td>
                                    @foreach ($columns as $column)
                                        <td @class([
                                            'whitespace-nowrap tabular' => $column['kind'] === 'date',
                                            'text-right tabular' => $column['kind'] === 'number',
                                            'max-w-56 truncate text-fg-2' => $column['kind'] === 'textarea',
                                            'font-medium' => $column['name'] === 'last_name',
                                        ])>
                                            @if ($column['name'] === 'timeliness')
                                                <span class="{{ Badge::TIMELINESS[$timely['status']] }}">{{ $timely['label'] }}</span>
                                            @elseif ($column['name'] === 'last_name')
                                                <a class="hover:text-primary-ink" href="{{ route('requirements.edit', [$type, $record->id]) }}">{{ $show($record, $column) }}</a>
                                            @else
                                                {{ $show($record, $column) }}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-pagination :paginator="$records" />
            @endif
        @elseif ($stats['missing'] === 0)
            <x-empty-state title="Everyone has complied" :text="'All active employees have a '.$year.' record with a Date Submitted.'" />
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Employee ID</th><th>Name</th><th>Position</th><th>Station</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($missing as $employee)
                            @php($overdue = in_array($employee->id, $overdueIds, true))
                            <tr>
                                <td class="font-mono text-xs">{{ $employee->employee_id }}</td>
                                <td><a class="font-medium hover:text-primary-ink" href="{{ route('employees.show', $employee) }}">{{ $employee->full_name }}</a></td>
                                <td>{{ $employee->position }}</td>
                                <td class="text-fg-2">{{ $employee->station?->name }}</td>
                                <td><span class="{{ Badge::TIMELINESS[$overdue ? 'overdue' : 'pending'] }}">{{ $overdue ? 'Overdue' : 'Pending' }}</span></td>
                                <td class="text-right"><a class="btn-secondary btn-sm" href="{{ route('requirements.create', [$type, 'year' => $year, 'employee' => $employee->id]) }}">Record</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$missing" />
        @endif
    </div>
</x-layouts.app>
