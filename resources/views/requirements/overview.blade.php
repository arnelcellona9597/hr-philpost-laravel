@php
    use App\Support\HrDate;

    $anyDeadline = collect($cards)->contains(fn (array $card): bool => collect($card['timely']['deadlines'])->contains(fn (array $deadline): bool => $deadline['deadline'] !== null));
@endphp

<x-layouts.app title="Annual Requirements" heading="Annual requirements" description="Yearly documents every active employee must submit. Compliance counts records with a Date Submitted; on time or late is judged against each requirement's deadline.">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('requirements.deadlines') }}"><x-icon name="calendar" /> Deadlines</a>
    </x-slot:actions>

    @unless ($anyDeadline)
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-primary-soft px-4 py-3 text-sm text-primary-ink" role="status">
            <span class="flex items-center gap-2"><x-icon name="info" class="size-4 shrink-0" /> Set the submission deadlines to see which employees submitted on time or late.</span>
            <a class="btn-primary btn-sm" href="{{ route('requirements.deadlines') }}">Set deadlines</a>
        </div>
    @endunless

    <div class="mb-4 flex items-center gap-2">
        <a class="btn-secondary btn-sm" href="{{ route('requirements.overview', ['year' => $year - 1]) }}" aria-label="Previous year">←</a>
        <span class="text-lg font-semibold tabular">{{ $year }}</span>
        <a class="btn-secondary btn-sm" href="{{ route('requirements.overview', ['year' => $year + 1]) }}" aria-label="Next year">→</a>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($cards as $card)
            @php
                $stats = $card['stats'];
                $counts = $card['timely']['counts'];
                $percent = $stats['active'] ? (int) round($stats['complied'] / $stats['active'] * 100) : 0;
                $due = collect($card['timely']['deadlines'])
                    ->filter(fn (array $deadline): bool => $deadline['deadline'] !== null)
                    ->map(fn (array $deadline): string => ($deadline['period'] ? "{$deadline['period']}: " : '').HrDate::format($deadline['deadline']).($deadline['source'] === 'override' ? ' (changed)' : ''))
                    ->implode(' · ');
            @endphp
            <a href="{{ route('requirements.index', [$card['route'], 'year' => $year]) }}" class="card group p-5 transition-colors hover:border-primary-ink">
                <h2 class="font-semibold group-hover:text-primary-ink">{{ $card['title'] }}</h2>
                <p class="mt-1 flex items-center gap-1.5 text-xs text-muted"><x-icon name="calendar" class="size-3.5" /> {{ $due !== '' ? "Due {$due}" : 'No deadline set' }}</p>
                <div class="mt-4 flex items-end justify-between">
                    <p class="text-3xl font-semibold tabular">{{ $percent }}%<span class="ml-2 text-sm font-normal text-muted">complied</span></p>
                    <p class="text-sm text-muted tabular">{{ $stats['complied'] }} of {{ $stats['active'] }} active</p>
                </div>
                <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-primary-soft" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="{{ $card['title'] }} compliance">
                    <div class="h-full rounded-full bg-primary" style="width:{{ $percent }}%"></div>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    <span @class(['text-warning' => $stats['missing'], 'text-success' => ! $stats['missing']])>
                        {{ $stats['missing'] ? $stats['missing'].' employee'.($stats['missing'] === 1 ? '' : 's').' still missing' : 'Everyone has complied' }}
                    </span>
                    @if ($due !== '')
                        <span class="flex flex-wrap gap-1.5">
                            <span class="badge-success">{{ $counts['on-time'] }} on time</span>
                            @if ($counts['late'] > 0)
                                <span class="badge-danger">{{ $counts['late'] }} late</span>
                            @endif
                            @if ($counts['overdue'] > 0)
                                <span class="badge-danger">{{ $counts['overdue'] }} overdue</span>
                            @endif
                        </span>
                    @endif
                </div>
            </a>
        @endforeach
    </div>
</x-layouts.app>
