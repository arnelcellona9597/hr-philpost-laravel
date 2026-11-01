@php
    use App\Support\HrDate;
    use App\Support\Money;
    use App\Support\ReportingPeriod as Reporting;

    $bucket = $period['bucket'];
    $buckets = Reporting::buckets($period['from'], $period['to'], $bucket);
    $sumInto = function (array $byDay) use ($buckets, $bucket): array {
        $totals = [];

        foreach ($byDay as $day => $count) {
            $key = Reporting::bucketKey($day, $bucket);
            $totals[$key] = ($totals[$key] ?? 0) + $count;
        }

        return array_map(fn (array $item): int => $totals[$item['key']] ?? 0, $buckets);
    };

    $active = $headcount['byStatus']['Active'] ?? 0;
    $hireSeries = $sumInto($movement['hiresByDay']);
    $separationSeries = $sumInto($movement['separationsByDay']);
    $hires = array_sum($hireSeries);
    $separations = array_sum($separationSeries);
    $net = $hires - $separations;
    $sparkHires = array_map(fn (array $month): int => $movement['hiresByMonth'][$month['key']] ?? 0, $sparkMonths);
    $sparkSeparations = array_map(fn (array $month): int => $movement['separationsByMonth'][$month['key']] ?? 0, $sparkMonths);

    $stageCount = fn (string $stage): int => $movement['pipeline'][$stage] ?? 0;
    $stages = ['Pending', 'For Interview', 'For OJT', 'Hired'];
    $inProgress = $stageCount('Pending') + $stageCount('For Interview') + $stageCount('For OJT');
    $stageMax = max(1, ...array_map($stageCount, $stages));
    $withActive = array_filter($compliance, fn (array $item): bool => $item['stats']['active'] > 0);
    $averageCompliance = $withActive === [] ? null : (int) round(array_sum(array_column($withActive, 'percent')) / count($withActive));
    $missingTotal = array_sum(array_map(fn (array $item): int => $item['stats']['missing'], $compliance));

    $classificationRows = collect($headcount['byClassification']);
    $activeByClass = array_map(fn (string $name): int => (int) $classificationRows->first(fn (object $row): bool => $row->classification === $name && $row->status === 'Active')?->total, $classifications);
    $genderOf = fn (?string $status, string $gender): int => (int) collect($headcount['byStatusGender'])->filter(fn (object $row): bool => ($status === null || $row->status === $status) && $row->gender === $gender)->sum('total');
    $genderRows = array_map(fn (?string $status): array => [
        'label' => $status ?? 'All employees',
        'parts' => [['label' => 'Male', 'value' => $genderOf($status, 'Male'), 'slot' => 1], ['label' => 'Female', 'value' => $genderOf($status, 'Female'), 'slot' => 2]],
    ], [null, ...$statuses]);
    $classSeries = array_map(fn (string $name, int $index): array => [
        'label' => $name,
        'slot' => $index + 1,
        'data' => array_map(fn (string $status): int => (int) $classificationRows->first(fn (object $row): bool => $row->classification === $name && $row->status === $status)?->total, $statuses),
    ], $classifications, array_keys($classifications));

    $top = 12;
    $topPositions = array_slice($headcount['positions'], 0, $top);
    $otherPositions = array_slice($headcount['positions'], $top);
    $others = array_sum(array_column($otherPositions, 'total'));
    $positionLabels = [...array_column($topPositions, 'position'), ...($others ? ['Others ('.count($otherPositions).' titles)'] : [])];
    $positionData = [...array_column($topPositions, 'total'), ...($others ? [$others] : [])];

    $link = function (string $path, array $extra = [], string $branchKey = 'branch') use ($branchId, $classification): string {
        $query = [...$extra, ...($branchId ? [$branchKey => $branchId] : []), ...($classification && $branchKey === 'branch' ? ['classification' => $classification] : [])];

        return url($path).($query === [] ? '' : '?'.preg_replace('/%5B\d+%5D/', '%5B%5D', http_build_query($query)));
    };

    $branchName = $branchId ? $branches->firstWhere('id', $branchId)?->name : null;
    $scopeNote = implode(' · ', array_filter([$branchName, $classification])) ?: 'All branches and classifications';
    $now = now('Asia/Manila');
    $greeting = $now->hour < 12 ? 'Good morning' : ($now->hour < 18 ? 'Good afternoon' : 'Good evening');
    $actionStyle = [
        'create' => ['plus', 'bg-success-soft text-success'],
        'update' => ['edit', 'bg-primary-soft text-primary-ink'],
        'delete' => ['trash', 'bg-danger-soft text-danger'],
        'import' => ['upload', 'bg-warning-soft text-warning'],
    ];
@endphp

<x-layouts.app title="Dashboard" :heading="$greeting.', '.$firstName" :description="$now->format('l, F j, Y').' · Here\'s how your workforce is doing.'">
    <x-slot:actions>
        <a href="{{ route('leave.create') }}" class="btn-secondary"><x-icon name="calendar" /> File leave</a>
        <a href="{{ route('employees.create') }}" class="btn-primary"><x-icon name="userPlus" /> Add employee</a>
    </x-slot:actions>

    <form method="get" class="card mb-6 grid grid-cols-2 items-end gap-3 p-3 sm:flex sm:flex-wrap sm:p-4" data-autosubmit aria-label="Dashboard filters">
        <div class="col-span-2 min-w-0 sm:col-span-1">
            <label class="label !mb-1 text-xs" for="period">Period</label>
            <select class="input py-1.5 sm:min-w-40" id="period" name="period">
                @if ($isCustom)
                    <option value="custom" selected>Custom range</option>
                @endif
                @foreach ($periods as $key => $label)
                    <option value="{{ $key }}" @selected($key === $period['key'])>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-0">
            <label class="label !mb-1 text-xs" for="from">From</label>
            <input class="input py-1.5" id="from" name="from" type="date" value="{{ $isCustom ? $period['from'] : '' }}" data-range-date />
        </div>
        <div class="min-w-0">
            <label class="label !mb-1 text-xs" for="to">To</label>
            <input class="input py-1.5" id="to" name="to" type="date" value="{{ $isCustom ? $period['to'] : '' }}" data-range-date />
        </div>
        <div class="min-w-0">
            <label class="label !mb-1 text-xs" for="branch">Branch</label>
            <select class="input py-1.5 sm:min-w-44" id="branch" name="branch">
                <option value="">All branches</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($branch->id === $branchId)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-0">
            <label class="label !mb-1 text-xs" for="classification">Classification</label>
            <select class="input py-1.5 sm:min-w-40" id="classification" name="classification">
                <option value="">All classifications</option>
                @foreach ($classifications as $option)
                    <option @selected($option === $classification)>{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button class="btn-secondary">Apply</button></noscript>
        @if ($branchId || $classification || $isCustom || $period['key'] !== Reporting::DEFAULT)
            <a href="{{ route('dashboard') }}" class="btn-ghost btn-sm mb-0.5">Reset</a>
        @endif
        <p class="col-span-2 text-xs text-muted sm:ml-auto sm:text-right">
            <span class="font-medium text-fg-2">{{ HrDate::format($period['from']) }} – {{ HrDate::format($period['to']) }}</span><br class="hidden sm:block" />
            <span class="sm:hidden"> · </span>{{ $period['prevLabel'] }}
        </p>
    </form>

    <section aria-label="Key figures" class="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 sm:gap-4 lg:grid-cols-3 2xl:grid-cols-6">
        <x-stat-card label="Active employees" :value="$active" icon="users" tone="primary" :note="($headcount['total'] ? round($active / $headcount['total'] * 1000) / 10 : 0).'% of '.number_format($headcount['total']).' records'.($headcount['asOf'] ? ' · as of '.HrDate::format($headcount['asOf']) : '')" :href="$link('/employees', ['status' => 'Active'])" />
        <x-stat-card label="New hires" :value="$hires" icon="userPlus" tone="success" :delta="['pct' => Reporting::percentChange($hires, $movement['prevHires']), 'label' => $period['prevLabel']]" :spark="$sparkHires" :spark-slot="1" :href="$link('/employees', ['hiredFrom' => $period['from'], 'hiredTo' => $period['to']])" />
        <x-stat-card label="Separations" :value="$separations" icon="userMinus" tone="danger" :delta="['pct' => Reporting::percentChange($separations, $movement['prevSeparations']), 'label' => $period['prevLabel'], 'invert' => true]" :spark="$sparkSeparations" :spark-slot="2" :href="$link('/employees', ['status' => ['Resigned', 'Retired', 'Terminated']])" />
        <x-stat-card label="Leave days filed" :value="$movement['leaveDays']" icon="calendar" tone="warning" :delta="['pct' => Reporting::percentChange($movement['leaveDays'], $movement['prevLeaveDays']), 'label' => $period['prevLabel']]" :note="$movement['leaveFilings'].' filing'.($movement['leaveFilings'] === 1 ? '' : 's').' · '.$movement['pendingLeave'].' pending'" :href="route('leave.index', ['year' => substr($period['to'], 0, 4)])" />
        <x-stat-card label="Applicants in progress" :value="$inProgress" icon="briefcase" tone="neutral" :note="$movement['incompleteRequirements'].' with incomplete requirements'" :href="$link('/onboarding', ['status' => ['Pending', 'For Interview', 'For OJT']], 'station')" />
        <x-stat-card :label="'Compliance '.$year" :value="$averageCompliance === null ? '—' : $averageCompliance.'%'" icon="checkCircle" tone="success" :note="$averageCompliance === null ? 'No active employees' : number_format($missingTotal).' submissions missing'" :href="route('requirements.overview', ['year' => $year])" />
    </section>

    <section class="mt-8" aria-labelledby="trends-title">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="trends-title" class="section-title">Workforce trends</h2>
            <p class="text-xs text-muted">{{ $scopeNote }}</p>
        </div>
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="min-w-0 lg:col-span-2">
                <x-charts.chart
                    title="Hires and separations"
                    :subtitle="$period['label'].' · separated = resigned, retired or terminated (by last day of service)'"
                    :spec="['kind' => 'area', 'labels' => array_column($buckets, 'label'), 'series' => [['label' => 'Hired', 'data' => $hireSeries, 'slot' => 1], ['label' => 'Separated', 'data' => $separationSeries, 'slot' => 2]], 'unit' => 'employees']"
                    :height="260"
                    :level="3"
                    empty-text="No hires or separations in this period."
                >
                    <x-slot:meta>
                        <span @class(['badge shrink-0', 'badge-success' => $net > 0, 'badge-danger' => $net < 0, 'badge-neutral' => $net === 0]) title="Hires minus separations">Net {{ $net > 0 ? '+' : '' }}{{ number_format($net) }}</span>
                    </x-slot:meta>
                </x-charts.chart>
            </div>
            <x-charts.chart
                title="Active workforce by classification"
                subtitle="Share of active employees"
                :spec="['kind' => 'doughnut', 'labels' => $classifications, 'series' => [['label' => 'Active employees', 'data' => $activeByClass, 'slot' => 1]], 'sliceSlots' => [1, 2, 3], 'unit' => 'employees']"
                :center="['value' => number_format($active), 'label' => 'active']"
                :level="3"
                empty-text="No active employees yet."
            />
        </div>
    </section>

    <section class="mt-8" aria-labelledby="stats-title">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="stats-title" class="section-title">Employee statistics</h2>
            <p class="text-xs text-muted">Headcount by gender, classification, status and position</p>
        </div>
        <div class="grid gap-4 xl:grid-cols-2">
            <x-charts.split-bars title="Male and female employees" subtitle="Share of each gender, overall and by employment status" :rows="$genderRows" :level="3" />
            <x-charts.chart title="Classification by employment status" subtitle="COS, Contractual and Regular employees in each status" :spec="['labels' => $statuses, 'series' => $classSeries, 'stacked' => true, 'unit' => 'employees']" :level="3" />
        </div>
        <div class="mt-4 grid gap-4 xl:grid-cols-5">
            <div class="min-w-0 xl:col-span-3">
                <x-charts.chart
                    title="Position / designation"
                    :subtitle="'Active employees per title'.($others ? ', top '.$top.' shown' : '')"
                    :spec="['labels' => $positionLabels, 'series' => [['label' => 'Active employees', 'data' => $positionData, 'slot' => 1]], 'horizontal' => true, 'valueLabels' => true, 'unit' => 'employees']"
                    empty-text="No active employees yet."
                    :level="3"
                />
            </div>
            <div class="min-w-0 space-y-4 xl:col-span-2">
                <section class="card p-4 sm:p-5" aria-labelledby="compliance-title">
                    <header class="mb-4 flex items-start justify-between gap-3">
                        <div>
                            <h3 id="compliance-title" class="text-sm font-semibold">Annual requirements {{ $year }}</h3>
                            <p class="mt-0.5 text-xs text-muted">Active employees with a submitted record</p>
                        </div>
                        <a href="{{ route('requirements.overview', ['year' => $year]) }}" class="shrink-0 text-xs font-medium text-primary-ink hover:underline">Open</a>
                    </header>
                    <ul class="space-y-3.5">
                        @foreach ($compliance as $item)
                            <li>
                                <a href="{{ route('requirements.index', [$item['route'], 'year' => $year]) }}" class="group block" title="{{ $item['title'] }}">
                                    <div class="mb-1.5 flex items-baseline justify-between gap-2 text-sm">
                                        <span class="font-medium text-fg-2 group-hover:text-fg">{{ $item['name'] }}</span>
                                        <span class="text-xs text-muted tabular">
                                            <span class="font-semibold text-fg">{{ $item['percent'] }}%</span> · {{ $item['stats']['complied'] }}/{{ $item['stats']['active'] }}
                                            @if ($item['stats']['missing'] > 0)
                                                <span class="text-warning"> · {{ $item['stats']['missing'] }} missing</span>
                                            @endif
                                            @if ($item['late'] > 0)
                                                <span class="text-danger"> · {{ $item['late'] }} late</span>
                                            @endif
                                        </span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-surface-3" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $item['percent'] }}" aria-label="{{ $item['name'] }} compliance">
                                        <div @class(['h-full rounded-full transition-[width] duration-500', 'bg-success' => $item['percent'] >= 100, 'bg-primary' => $item['percent'] < 100]) style="width:{{ $item['percent'] }}%"></div>
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="card p-4 sm:p-5" aria-labelledby="pipeline-title">
                    <header class="mb-4 flex items-start justify-between gap-3">
                        <div>
                            <h3 id="pipeline-title" class="text-sm font-semibold">Onboarding pipeline</h3>
                            <p class="mt-0.5 text-xs text-muted">Applicants at each stage{{ $branchName ? " · {$branchName}" : '' }}</p>
                        </div>
                        <a href="{{ route('onboarding.index') }}" class="shrink-0 text-xs font-medium text-primary-ink hover:underline">Open</a>
                    </header>
                    <ol class="space-y-2.5">
                        @foreach ($stages as $index => $stage)
                            <li>
                                <a href="{{ $link('/onboarding', ['status' => $stage], 'station') }}" class="group flex items-center gap-3 text-sm">
                                    <span class="w-24 shrink-0 text-fg-2 group-hover:text-fg">{{ $stage }}</span>
                                    <span class="relative h-6 flex-1 overflow-hidden rounded-md bg-surface-2">
                                        <span class="absolute inset-y-0 left-0 rounded-md" style="width:{{ $stageCount($stage) / $stageMax * 100 }}%; background: var(--series-{{ $index === 3 ? 3 : 1 }}); opacity:{{ $index === 3 ? 1 : 0.55 + $index * 0.15 }}"></span>
                                    </span>
                                    <span class="w-8 shrink-0 text-right font-semibold tabular">{{ $stageCount($stage) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ol>
                    <p class="mt-3 text-xs text-muted">
                        {{ $stageCount('Not Hired') }} not hired · {{ $stageCount('Withdrawn') }} withdrawn ·
                        <a href="{{ $link('/onboarding', ['requirements' => 'Incomplete'], 'station') }}" class="text-primary-ink hover:underline">{{ $movement['incompleteRequirements'] }} incomplete requirements</a>
                    </p>
                </section>
            </div>
        </div>
    </section>

    <section class="mt-8" aria-labelledby="recent-title">
        <h2 id="recent-title" class="section-title mb-3">Recent activity</h2>
        <div class="grid gap-4 xl:grid-cols-3">
            <div class="min-w-0 space-y-4 xl:col-span-2">
                <section class="card overflow-hidden" aria-labelledby="hires-title">
                    <header class="flex items-center justify-between gap-3 px-4 pt-4 sm:px-5">
                        <h3 id="hires-title" class="text-sm font-semibold">Latest hires</h3>
                        <a href="{{ $link('/employees', ['sort' => '-dateHired']) }}" class="text-xs font-medium text-primary-ink hover:underline">View all</a>
                    </header>
                    @if ($recentHires->isNotEmpty())
                        <div class="mt-3 overflow-x-auto">
                            <table class="table">
                                <thead><tr><th>Name</th><th>Position</th><th class="hidden md:table-cell">Branch</th><th class="text-right">Hired</th></tr></thead>
                                <tbody>
                                    @foreach ($recentHires as $employee)
                                        <tr>
                                            <td class="font-medium"><a href="{{ route('employees.show', $employee) }}" class="hover:text-primary-ink hover:underline">{{ $employee->full_name }}</a></td>
                                            <td class="text-fg-2">{{ $employee->position ?: '—' }}</td>
                                            <td class="hidden text-fg-2 md:table-cell">{{ $employee->station?->name ?: '—' }}</td>
                                            <td class="whitespace-nowrap text-right text-fg-2 tabular">{{ HrDate::format($employee->date_hired) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="px-5 py-10 text-center text-sm text-muted">No hire dates recorded yet.</p>
                    @endif
                </section>

                <div class="grid gap-4 md:grid-cols-2">
                    <section class="card p-4 sm:p-5" aria-labelledby="pending-title">
                        <header class="mb-3 flex items-center justify-between gap-3">
                            <h3 id="pending-title" class="text-sm font-semibold">Pending leave filings</h3>
                            <a href="{{ route('leave.index', ['status' => 'Pending']) }}" class="text-xs font-medium text-primary-ink hover:underline">View all</a>
                        </header>
                        @if ($pendingLeaves->isNotEmpty())
                            <ul class="-mx-2 divide-y divide-line">
                                @foreach ($pendingLeaves as $leave)
                                    <li>
                                        <a href="{{ route('leave.edit', $leave) }}" class="flex items-center justify-between gap-3 rounded-lg px-2 py-2.5 transition-colors hover:bg-surface-2">
                                            <span class="min-w-0">
                                                <span class="block truncate text-sm font-medium">{{ collect([$leave->last_name, $leave->first_name])->filter()->implode(', ') }}</span>
                                                <span class="block text-xs text-muted">{{ HrDate::format($leave->inclusive_date_from) }}{{ $leave->inclusive_date_to != $leave->inclusive_date_from ? ' – '.HrDate::format($leave->inclusive_date_to) : '' }}</span>
                                            </span>
                                            <span class="badge-warning shrink-0 tabular">{{ $leave->days ?? 0 }} day{{ $leave->days === 1 ? '' : 's' }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <div class="flex flex-col items-center gap-2 py-8 text-center">
                                <span class="flex size-9 items-center justify-center rounded-full bg-success-soft text-success"><x-icon name="check" /></span>
                                <p class="text-sm text-muted">Nothing waiting for approval.</p>
                            </div>
                        @endif
                    </section>

                    <section class="card flex flex-col p-4 sm:p-5" aria-labelledby="payroll-title">
                        <header class="mb-3 flex items-center justify-between gap-3">
                            <h3 id="payroll-title" class="text-sm font-semibold">Latest payroll</h3>
                            <a href="{{ route('payroll.index') }}" class="text-xs font-medium text-primary-ink hover:underline">All periods</a>
                        </header>
                        @if ($latestPayroll)
                            <a href="{{ route('payroll.show', $latestPayroll) }}" class="group flex flex-1 flex-col rounded-lg border border-line p-4 transition-colors hover:border-line-strong hover:bg-surface-2">
                                <span class="flex items-start justify-between gap-2">
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold group-hover:text-primary-ink">{{ $latestPayroll->name }}</span>
                                        <span class="block text-xs text-muted">{{ HrDate::format($latestPayroll->period_start) }} – {{ HrDate::format($latestPayroll->period_end) }}</span>
                                    </span>
                                    <span class="{{ $latestPayroll->isReleased() ? 'badge-success' : 'badge-warning' }}">{{ $latestPayroll->status->value }}</span>
                                </span>
                                <span class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                    <span>
                                        <span class="block text-xs text-muted">Net pay total</span>
                                        <span class="block text-lg font-semibold tabular">{{ Money::format((int) $latestPayroll->net_total) }}</span>
                                    </span>
                                    <span>
                                        <span class="block text-xs text-muted">Payslips</span>
                                        <span class="block text-lg font-semibold tabular">{{ number_format($latestPayroll->payslips_count) }}</span>
                                    </span>
                                </span>
                                <span class="mt-3 text-xs text-muted">Pay date {{ HrDate::format($latestPayroll->pay_date) }}</span>
                            </a>
                        @else
                            <div class="flex flex-1 flex-col items-center justify-center gap-2 py-8 text-center">
                                <span class="flex size-9 items-center justify-center rounded-full bg-surface-2 text-muted"><x-icon name="wallet" /></span>
                                <p class="text-sm text-muted">No payroll periods yet.</p>
                                <a href="{{ route('payroll.create') }}" class="btn-secondary btn-sm mt-1">Create one</a>
                            </div>
                        @endif
                    </section>
                </div>
            </div>

            <section class="card p-4 sm:p-5" aria-labelledby="timeline-title">
                <header class="mb-4 flex items-center justify-between gap-3">
                    <h3 id="timeline-title" class="text-sm font-semibold">Activity</h3>
                    <a href="{{ route('audit.index') }}" class="text-xs font-medium text-primary-ink hover:underline">Audit log</a>
                </header>
                @if ($activity->isNotEmpty())
                    <ol class="relative space-y-4 before:absolute before:inset-y-2 before:left-[15px] before:w-px before:bg-line">
                        @foreach ($activity as $entry)
                            @php([$icon, $iconTone] = $actionStyle[$entry['action']] ?? ['activity', 'bg-surface-2 text-fg-2'])
                            <li class="relative flex gap-3">
                                <span class="{{ $iconTone }} relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full ring-4 ring-surface" aria-hidden="true">
                                    <x-icon :name="$icon" class="size-3.5" />
                                </span>
                                <div class="min-w-0 pt-1 text-sm">
                                    <p class="text-fg-2">
                                        <span class="font-medium text-fg">{{ $entry['who'] }}</span> {{ $entry['verb'] }} {{ $entry['what'] }}
                                        @if ($entry['record'] && $entry['href'])
                                            <a href="{{ url($entry['href']) }}" class="font-medium text-fg hover:text-primary-ink hover:underline">{{ $entry['record'] }}</a>
                                        @elseif ($entry['record'])
                                            <span class="font-medium text-fg">{{ $entry['record'] }}</span>
                                        @endif
                                    </p>
                                    <time datetime="{{ $entry['at']?->toIso8601String() }}" class="text-xs text-muted" title="{{ $entry['at']?->timezone('Asia/Manila')->format('M j, Y g:i A') }}">{{ $entry['at']?->diffForHumans() }}</time>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @else
                    <p class="py-8 text-center text-sm text-muted">No activity recorded yet.</p>
                @endif
            </section>
        </div>
    </section>
</x-layouts.app>
