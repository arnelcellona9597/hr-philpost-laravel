@php
    use App\Enums\LeaveStatus;
    use App\Support\Badge;
    use App\Support\HrDate;

    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $daysUsed = array_sum(array_column($stats['byMonth'], 'days'));
    $filings = array_sum(array_column($stats['byMonth'], 'filings'));
    $closed = ($stats['byStatus']['Disapproved'] ?? 0) + ($stats['byStatus']['Cancelled'] ?? 0);
    $monthlyDays = array_map(fn (int $month): int => $stats['byMonth'][$month]['days'] ?? 0, range(1, 12));
    $yearUrl = fn (int $target): string => route('leave.index', [...request()->except('page'), 'year' => $target]);
    $filtered = $text !== '' || $status !== null || $employeeId !== null;
@endphp

<x-layouts.app title="Wellness Leave" heading="Wellness leave" :description="$allowance.' wellness leave day'.($allowance === 1 ? '' : 's').' per employee in '.$year.'. Weekends and holidays are not counted.'">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('leave.settings') }}"><x-icon name="settings" /> Allowance &amp; holidays</a>
        <a class="btn-secondary" href="{{ url("/data/export?module=wellness-leaves&year={$year}") }}"><x-icon name="download" /> Export {{ $year }}</a>
        <a class="btn-primary" href="{{ route('leave.create', array_filter(['employee' => $employeeId])) }}"><x-icon name="plus" /> File leave</a>
    </x-slot:actions>

    <div class="mb-4 flex items-center gap-2">
        <a class="btn-secondary btn-sm" href="{{ $yearUrl($year - 1) }}" aria-label="Previous year">←</a>
        <span class="text-lg font-semibold tabular">{{ $year }}</span>
        <a class="btn-secondary btn-sm" href="{{ $yearUrl($year + 1) }}" aria-label="Next year">→</a>
        @if ($year !== $thisYear)
            <a class="btn-ghost btn-sm" href="{{ $yearUrl($thisYear) }}">This year</a>
        @endif
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat-card label="Leave filings" :value="$filings" note="Approved and pending" />
        <x-stat-card label="Days used" :value="$daysUsed" :note="'of '.number_format($allowance * $activeCount).' available to active staff'" />
        <x-stat-card label="Employees who filed" :value="$stats['employeesWithLeave']" :note="'of '.$activeCount.' active employees'" />
        <x-stat-card label="Disapproved / cancelled" :value="$closed" />
    </div>

    <div class="mt-4 grid items-start gap-4 xl:grid-cols-2">
        <x-charts.chart
            title="Leave days per month"
            :subtitle="'Working days taken in '.$year.', by month the leave starts'"
            :spec="['labels' => $months, 'series' => [['label' => 'Days taken', 'data' => $monthlyDays, 'slot' => 1]], 'valueLabels' => true, 'unit' => 'days']"
            :height="240"
        />
        <x-charts.chart
            title="Most leave days used"
            :subtitle="'Top employees in '.$year.' (allowance: '.$allowance.' days)'"
            :spec="[
                'labels' => array_column($stats['topUsers'], 'name'),
                'series' => [
                    ['label' => 'Used', 'data' => array_column($stats['topUsers'], 'days'), 'slot' => 1],
                    ['label' => 'Remaining', 'data' => array_map(fn (array $user): int => max(0, $allowance - $user['days']), $stats['topUsers']), 'slot' => 3],
                ],
                'stacked' => true,
                'horizontal' => true,
                'unit' => 'days',
            ]"
            :empty-text="'No leave filed in '.$year.'.'"
        />
    </div>

    <form method="get" class="card mt-6 flex flex-wrap items-end gap-3 p-4">
        <input type="hidden" name="year" value="{{ $year }}" />
        @if ($employeeId)
            <input type="hidden" name="employee" value="{{ $employeeId }}" />
        @endif
        <div class="min-w-56 flex-1">
            <label class="label" for="q">Search</label>
            <input class="input" id="q" name="q" type="search" value="{{ $text }}" placeholder="Employee name or remarks" />
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All</option>
                @foreach (LeaveStatus::cases() as $option)
                    <option @selected($option === $status)>{{ $option->value }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn-primary">Filter</button>
        @if ($filtered)
            <a class="btn-ghost" href="{{ route('leave.index', ['year' => $year]) }}">Clear</a>
        @endif
    </form>
    @if ($employee)
        <p class="mt-3 text-sm text-fg-2">Showing filings for <a class="font-medium text-primary-ink hover:underline" href="{{ route('employees.show', $employee) }}">{{ $employee->full_name }}</a>.</p>
    @endif

    <div class="card mt-4 overflow-hidden">
        @if ($leaves->total() === 0)
            <x-empty-state :title="'No leave filings for '.$year" text="Filed wellness leave will show here.">
                <a class="btn-primary" href="{{ route('leave.create') }}">File leave</a>
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Last name</th><th>First name</th><th>Middle name</th><th>Date filing</th><th>Inclusive date</th>
                            <th>Date received</th><th class="text-right">Days</th><th class="text-right">Remaining days</th><th>Status</th><th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($leaves as $leave)
                            <tr>
                                <td class="font-medium"><a class="hover:text-primary-ink" href="{{ route('leave.edit', $leave) }}">{{ $leave->last_name }}</a></td>
                                <td>{{ $leave->first_name }}</td>
                                <td class="text-fg-2">{{ $leave->middle_name }}</td>
                                <td class="whitespace-nowrap tabular">{{ HrDate::format($leave->date_filing) }}</td>
                                <td class="whitespace-nowrap tabular">
                                    {{ HrDate::format($leave->inclusive_date_from) }}
                                    @unless ($leave->inclusive_date_from->eq($leave->inclusive_date_to))
                                        – {{ HrDate::format($leave->inclusive_date_to) }}
                                    @endunless
                                </td>
                                <td class="whitespace-nowrap tabular text-fg-2">{{ HrDate::format($leave->date_received) }}</td>
                                <td class="text-right tabular">{{ $leave->days }}</td>
                                <td class="text-right tabular font-medium">{{ $remaining[$leave->id] ?? '' }}</td>
                                <td><span class="{{ Badge::for($leave->status) }}">{{ $leave->status->value }}</span></td>
                                <td class="max-w-56 truncate text-fg-2" title="{{ $leave->remarks }}">{{ $leave->remarks }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$leaves" />
        @endif
    </div>
    <p class="mt-2 text-xs text-muted">Remaining days = the employee's balance for that year right after the filing, counting filings in date order.</p>
</x-layouts.app>
