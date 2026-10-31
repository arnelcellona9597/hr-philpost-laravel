{{-- KPI card. Every number is real text; the arrow and colour of the change are backed by a screen-reader sentence. --}}
@props(['label', 'value', 'note' => null, 'href' => null, 'tone' => 'neutral', 'icon' => null, 'delta' => null, 'spark' => null, 'sparkSlot' => 1])

@php
    $tag = $href ? 'a' : 'div';
    $dot = ['neutral' => 'bg-muted', 'success' => 'bg-success', 'warning' => 'bg-[var(--brand-warning)]', 'danger' => 'bg-danger', 'primary' => 'bg-primary'][$tone];
    $iconTone = [
        'neutral' => 'bg-surface-2 text-fg-2',
        'success' => 'bg-success-soft text-success',
        'warning' => 'bg-warning-soft text-warning',
        'danger' => 'bg-danger-soft text-danger',
        'primary' => 'bg-primary-soft text-primary-ink',
    ][$tone];

    $percent = $delta['pct'] ?? null;
    $deltaLabel = $delta['label'] ?? '';
    $direction = $percent === null || abs($percent) < 0.05 ? 'flat' : ($percent > 0 ? 'up' : 'down');
    $good = $direction === 'flat' ? null : ($direction === 'up') !== ($delta['invert'] ?? false);
    $deltaClass = $good === null ? 'bg-surface-2 text-muted' : ($good ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger');
    $deltaText = $percent === null ? '—' : (abs($percent) >= 100 ? round(abs($percent)) : number_format(abs($percent), 1)).'%';
    $deltaSentence = match (true) {
        $percent === null => "No earlier data to compare {$deltaLabel}",
        $direction === 'flat' => "Unchanged {$deltaLabel}",
        default => ($direction === 'up' ? 'Up' : 'Down')." {$deltaText} {$deltaLabel}",
    };
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif @class(['card group relative flex flex-col p-4 sm:p-5', 'card-hover' => $href])>
    <div class="flex items-start justify-between gap-3">
        <p class="flex min-w-0 items-center gap-2 text-[13px] font-medium text-muted">
            @unless ($icon)
                <span @class(['inline-block size-2 shrink-0 rounded-full', $dot]) aria-hidden="true"></span>
            @endunless
            <span class="truncate">{{ $label }}</span>
        </p>
        @if ($icon)
            <span @class(['flex size-8 shrink-0 items-center justify-center rounded-lg', $iconTone]) aria-hidden="true">
                <x-icon :name="$icon" class="size-4" />
            </span>
        @endif
    </div>
    <div class="mt-3 flex items-end justify-between gap-3">
        <p class="text-[1.75rem] font-semibold leading-none tracking-tight text-fg tabular">{{ is_int($value) ? number_format($value) : $value }}</p>
        @if ($spark && max($spark) > 0)
            <x-charts.sparkline :data="$spark" :series="$sparkSlot" />
        @endif
    </div>
    @if ($delta || $note)
        <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
            @if ($delta)
                <span @class(['inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-medium tabular', $deltaClass]) aria-hidden="true">
                    <x-icon :name="match ($direction) { 'up' => 'trendingUp', 'down' => 'trendingDown', default => 'minus' }" class="size-3.5" />
                    {{ $deltaText }}
                </span>
                <span class="sr-only">{{ $deltaSentence }}</span>
                @if ($deltaLabel !== '')
                    <span class="text-muted" aria-hidden="true">{{ $deltaLabel }}</span>
                @endif
            @endif
            @if ($note)
                <span @class(['text-muted', 'basis-full' => $delta])>{{ $note }}</span>
            @endif
        </div>
    @endif
</{{ $tag }}>
