{{-- A chart card: canvas, an HTML legend for 2+ series, and a table view so every value is readable without hovering or relying on color. --}}
@props(['title', 'subtitle' => null, 'spec', 'height' => null, 'emptyText' => 'No data yet.', 'level' => 2, 'center' => null, 'meta' => null])

@php
    use Illuminate\Support\Str;

    $kind = $spec['kind'] ?? 'bar';
    $labels = $spec['labels'];
    $series = $spec['series'];
    $isEmpty = $labels === [] || collect($series)->every(fn (array $line): bool => ! array_filter($line['data']));
    $height ??= match (true) {
        $kind === 'bar' && ($spec['horizontal'] ?? false) => max(160, count($labels) * 30 + 40),
        $kind === 'doughnut' => 190,
        default => 280,
    };
    $id = 'chart-'.Str::lower(Str::random(7));
    $heading = $level === 3 ? 'h3' : 'h2';
    $totals = array_map(fn (int $index): int|float => array_sum(array_map(fn (array $line) => $line['data'][$index] ?? 0, $series)), array_keys($labels));
    $doughnutTotal = $kind === 'doughnut' ? array_sum($series[0]['data'] ?? []) : 0;
    $share = fn (int|float $value): string => $doughnutTotal ? (round($value / $doughnutTotal * 1000) / 10).'%' : '0%';
    $sliceSlot = fn (int $index): int => $spec['sliceSlots'][$index] ?? ($index % 4) + 1;
@endphp

<section class="card flex min-w-0 flex-col p-4 sm:p-5" aria-labelledby="{{ $id }}-title">
    <header class="mb-4 flex items-start justify-between gap-3">
        <div class="min-w-0">
            <{{ $heading }} id="{{ $id }}-title" class="text-sm font-semibold text-fg">{{ $title }}</{{ $heading }}>
            @if ($subtitle)
                <p class="mt-0.5 text-xs text-muted">{{ $subtitle }}</p>
            @endif
        </div>
        {{ $meta }}
    </header>
    @if ($isEmpty)
        <div class="flex flex-1 flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-line py-10 text-center" style="min-height:{{ min($height, 220) }}px">
            <span class="flex size-9 items-center justify-center rounded-full bg-surface-2 text-muted">
                <x-icon name="activity" class="size-4" />
            </span>
            <p class="text-sm text-muted">{{ $emptyText }}</p>
        </div>
    @else
        @if ($kind === 'doughnut')
            <div class="flex flex-1 flex-col items-center justify-center gap-5">
                <div class="relative w-full max-w-[200px]" style="height:{{ $height }}px">
                    <canvas class="block h-full w-full max-w-full" data-chart="{{ json_encode($spec) }}" role="img" aria-label="{{ $title }}. Values are listed below the chart."></canvas>
                    @if ($center)
                        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                            <span class="text-2xl font-semibold tracking-tight tabular">{{ $center['value'] }}</span>
                            <span class="text-xs text-muted">{{ $center['label'] }}</span>
                        </div>
                    @endif
                </div>
                <ul class="w-full space-y-2 text-sm" aria-label="Legend">
                    @foreach ($labels as $index => $label)
                        <li class="flex items-center gap-2.5">
                            <span class="inline-block size-2.5 shrink-0 rounded-sm" style="background: var(--series-{{ $sliceSlot($index) }})"></span>
                            <span class="min-w-0 flex-1 truncate text-fg-2">{{ $label }}</span>
                            <span class="font-medium tabular">{{ number_format($series[0]['data'][$index] ?? 0) }}</span>
                            <span class="w-12 text-right text-xs text-muted tabular">{{ $share($series[0]['data'][$index] ?? 0) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @else
            @if (count($series) > 1)
                <ul class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-fg-2" aria-label="Legend">
                    @foreach ($series as $line)
                        <li class="flex items-center gap-1.5">
                            <span class="inline-block size-2.5 rounded-sm" style="background: var(--series-{{ $line['slot'] }})"></span>
                            {{ $line['label'] }}
                        </li>
                    @endforeach
                </ul>
            @endif
            <div class="relative" style="height:{{ $height }}px">
                <canvas class="block h-full w-full max-w-full" data-chart="{{ json_encode($spec) }}" role="img" aria-label="{{ $title }}. Values are listed in the table below."></canvas>
            </div>
        @endif
        <details class="mt-3 text-sm">
            <summary class="cursor-pointer select-none text-xs font-medium text-muted transition-colors hover:text-fg">View as table</summary>
            <div class="mt-2 max-h-72 overflow-auto rounded-lg border border-line">
                <table class="table tabular">
                    <thead>
                        <tr>
                            <th scope="col">Category</th>
                            @foreach ($series as $line)
                                <th scope="col" class="text-right">{{ $line['label'] }}</th>
                            @endforeach
                            @if (count($series) > 1)
                                <th scope="col" class="text-right">Total</th>
                            @endif
                            @if ($kind === 'doughnut')
                                <th scope="col" class="text-right">Share</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($labels as $index => $label)
                            <tr>
                                <th scope="row" class="!bg-transparent !text-sm !font-normal !normal-case !tracking-normal !text-fg">{{ $label }}</th>
                                @foreach ($series as $line)
                                    <td class="text-right">{{ number_format($line['data'][$index] ?? 0) }}</td>
                                @endforeach
                                @if (count($series) > 1)
                                    <td class="text-right font-medium">{{ number_format($totals[$index]) }}</td>
                                @endif
                                @if ($kind === 'doughnut')
                                    <td class="text-right">{{ $share($totals[$index]) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</section>
