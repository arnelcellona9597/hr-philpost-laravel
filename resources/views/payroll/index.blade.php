@php
    use App\Enums\PayrollStatus;
    use App\Support\HrDate;
    use App\Support\Money;
    use Illuminate\Support\Carbon;

    $monthNames = collect(range(1, 12))->mapWithKeys(fn (int $month): array => [$month => Carbon::create(2000, $month, 1)->format('F')]);
@endphp

<x-layouts.app title="Payroll" heading="COS Payroll" description="Semi-monthly payroll for Contract of Service workers: the 1st half (1–15) and the 2nd half (16–end of month), each with its own review, approval and release, plus a monthly summary of both.">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('payroll.settings') }}"><x-icon name="settings" /> Payslip template</a>
        <a class="btn-primary" href="{{ route('payroll.create') }}"><x-icon name="plus" /> New payroll period</a>
    </x-slot:actions>

    <form method="get" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="label" for="f-year">Year</label>
            <select class="input" id="f-year" name="year">
                <option value="">All years</option>
                @foreach ($years as $year)
                    <option value="{{ $year }}" @selected($filters['year'] === $year)>{{ $year }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="f-month">Month</label>
            <select class="input" id="f-month" name="month">
                <option value="">All months</option>
                @foreach ($monthNames as $number => $name)
                    <option value="{{ $number }}" @selected($filters['month'] === $number)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="f-half">Payroll period</label>
            <select class="input" id="f-half" name="half">
                <option value="">Both halves</option>
                <option value="1" @selected($filters['half'] === 1)>1st half (1–15)</option>
                <option value="2" @selected($filters['half'] === 2)>2nd half (16–end)</option>
            </select>
        </div>
        <div>
            <label class="label" for="f-status">Status</label>
            <select class="input" id="f-status" name="status">
                <option value="">Any status</option>
                @foreach (PayrollStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected($filters['status'] === $status)>{{ $status->value }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn-secondary"><x-icon name="filter" /> Filter</button>
        @if (array_filter($filters))
            <a class="btn-ghost" href="{{ route('payroll.index') }}">Clear</a>
        @endif
    </form>

    @if ($months->isEmpty())
        <div class="card">
            <x-empty-state title="No payroll periods {{ array_filter($filters) ? 'match these filters' : 'yet' }}" text="Create the 1st half (1–15) or 2nd half (16–end) of a month, then add the COS workers.">
                <a class="btn-primary" href="{{ route('payroll.create') }}">New payroll period</a>
            </x-empty-state>
        </div>
    @endif

    @foreach ($months as $key => $periods)
        @php([$year, $month] = array_map('intval', explode('-', $key)))
        <section class="card mb-4 overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
                <h2 class="font-semibold">{{ $monthNames[$month] }} {{ $year }}</h2>
                <div class="flex flex-wrap gap-2">
                    @foreach ([1, 2] as $half)
                        @unless ($periods->contains(fn ($period) => ($period->half ?? ($period->period_start->day <= 15 ? 1 : 2)) === $half))
                            <a class="btn-ghost btn-sm" href="{{ route('payroll.create', ['year' => $year, 'month' => $month, 'half' => $half]) }}"><x-icon name="plus" class="size-3.5" /> {{ $half === 1 ? '1st half (1–15)' : '2nd half (16–end)' }}</a>
                        @endunless
                    @endforeach
                    <a class="btn-secondary btn-sm" href="{{ route('payroll.monthly', [$year, $month]) }}"><x-icon name="sheet" class="size-3.5" /> Monthly summary</a>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th>Period</th><th>Coverage</th><th>Pay date</th><th class="text-right">Workers</th><th class="text-right">Gross</th><th class="text-right">Net</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($periods->sortBy('period_start') as $period)
                            <tr>
                                <td class="font-medium">
                                    <a class="hover:text-primary-ink" href="{{ route('payroll.show', $period) }}">{{ $period->name }}</a>
                                    <span class="block font-mono text-[11px] text-muted">{{ $period->code }}{{ $period->batch ? ' · Batch '.$period->batch : '' }}</span>
                                </td>
                                <td class="whitespace-nowrap tabular text-fg-2">{{ HrDate::format($period->period_start) }} – {{ HrDate::format($period->period_end) }}</td>
                                <td class="whitespace-nowrap tabular">{{ HrDate::format($period->pay_date) }}</td>
                                <td class="text-right tabular">{{ $period->payslips_count }}</td>
                                <td class="text-right tabular">{{ Money::format((int) $period->gross_total) }}</td>
                                <td class="text-right font-medium tabular">{{ Money::format((int) $period->net_total) }}</td>
                                <td>
                                    <span class="{{ $period->status->badge() }}">{{ $period->status->value }}</span>
                                    @if ($period->corrected_count > 0)
                                        <span class="badge-danger ml-1" title="Payslips changed after release">{{ $period->corrected_count }} corrected</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach
</x-layouts.app>
