{{-- 100% split bars (e.g. Male vs Female per employment status). Plain HTML, so labels, counts and percentages are real text. --}}
@props(['title', 'subtitle' => null, 'rows', 'level' => 2])

@php
    $heading = $level === 3 ? 'h3' : 'h2';
    $legend = $rows[0]['parts'] ?? [];
    $percent = fn (int|float $value, int|float $total): float|int => $total ? round($value / $total * 1000) / 10 : 0;
@endphp

<section class="card min-w-0 p-4 sm:p-5">
    <header class="mb-4">
        <{{ $heading }} class="text-sm font-semibold text-fg">{{ $title }}</{{ $heading }}>
        @if ($subtitle)
            <p class="mt-0.5 text-xs text-muted">{{ $subtitle }}</p>
        @endif
    </header>
    <ul class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-fg-2" aria-label="Legend">
        @foreach ($legend as $item)
            <li class="flex items-center gap-1.5">
                <span class="inline-block size-2.5 rounded-sm" style="background: var(--series-{{ $item['slot'] }})"></span>
                {{ $item['label'] }}
            </li>
        @endforeach
    </ul>
    <dl class="space-y-3">
        @foreach ($rows as $row)
            @php
                $total = array_sum(array_column($row['parts'], 'value'));
                $summary = array_map(fn (array $part): string => "{$part['label']} ".number_format($part['value']).' ('.$percent($part['value'], $total).'%)', $row['parts']);
            @endphp
            <div>
                <dt class="mb-1 flex items-baseline justify-between text-xs">
                    <span class="font-medium text-fg-2">{{ $row['label'] }}</span>
                    <span class="tabular text-muted">{{ number_format($total) }} total</span>
                </dt>
                <dd>
                    @if ($total === 0)
                        <div class="h-5 rounded-md bg-surface-2"></div>
                    @else
                        <div class="flex h-5 gap-[2px] overflow-hidden rounded-md" role="img" aria-label="{{ implode(', ', $summary) }}">
                            @foreach ($row['parts'] as $part)
                                @if ($part['value'] > 0)
                                    <div class="min-w-[3px] transition-opacity hover:opacity-80" style="flex: {{ $part['value'] }} 1 0; background: var(--series-{{ $part['slot'] }})" title="{{ $part['label'] }}: {{ number_format($part['value']) }} ({{ $percent($part['value'], $total) }}%)"></div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                    <p class="mt-1 text-xs text-muted tabular">{{ implode(' · ', $summary) }}</p>
                </dd>
            </div>
        @endforeach
    </dl>
</section>
