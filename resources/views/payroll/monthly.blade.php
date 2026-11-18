@php
    $month = \Illuminate\Support\Carbon::create($summary['year'], $summary['month'], 1);
    $previous = $month->copy()->subMonth();
    $next = $month->copy()->addMonth();
@endphp

<x-layouts.print :title="'Monthly payroll summary — '.$month->format('F Y')" paper="Legal landscape" margin="8mm" :back="route('payroll.index', ['year' => $summary['year'], 'month' => $summary['month']])">
    <div class="no-print mx-auto mb-4 flex max-w-[13.5in] flex-wrap items-center justify-between gap-2 px-2">
        <div class="flex gap-2">
            <a class="btn-ghost btn-sm" href="{{ route('payroll.monthly', [$previous->year, $previous->month]) }}">← {{ $previous->format('F Y') }}</a>
            <a class="btn-ghost btn-sm" href="{{ route('payroll.monthly', [$next->year, $next->month]) }}">{{ $next->format('F Y') }} →</a>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach ($summary['periods'] as $half => $period)
                @if ($period)
                    <a class="btn-secondary btn-sm" href="{{ route('payroll.show', $period) }}">{{ $half === 1 ? '1st half' : '2nd half' }} · {{ $period->status->value }}</a>
                @else
                    <a class="btn-secondary btn-sm" href="{{ route('payroll.create', ['year' => $summary['year'], 'month' => $summary['month'], 'half' => $half]) }}">Create {{ $half === 1 ? '1st half (1–15)' : '2nd half (16–end)' }}</a>
                @endif
            @endforeach
            <a class="btn-secondary btn-sm" href="{{ route('payroll.monthly.pdf', [$summary['year'], $summary['month']]) }}">PDF</a>
            <a class="btn-secondary btn-sm" href="{{ route('payroll.monthly.export', [$summary['year'], $summary['month']]) }}">Excel</a>
        </div>
    </div>
    <article class="sheet mx-auto mb-6 max-w-[13.5in] rounded-lg border border-line bg-white p-6 shadow-sm">
        @include('payroll._monthly')
    </article>
</x-layouts.print>
