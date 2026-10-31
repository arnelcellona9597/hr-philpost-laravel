{{-- Tiny trend line rendered on the server as SVG. Decorative: the card states the numbers in text. --}}
@props(['data', 'series' => 1, 'width' => 96, 'height' => 32])

@php
    use Illuminate\Support\Str;

    $id = 'spark-'.Str::lower(Str::random(7));
    $count = count($data);
    $max = max(1, ...$data);
    $pad = 2;
    $step = $count > 1 ? ($width - $pad * 2) / ($count - 1) : 0;
    $points = array_map(fn (int|float $value, int $index): array => [$pad + $index * $step, $height - $pad - ($value / $max) * ($height - $pad * 2)], array_values($data), array_keys(array_values($data)));
    $line = implode(' ', array_map(fn (array $point, int $index): string => sprintf('%s%.1F %.1F', $index ? 'L' : 'M', $point[0], $point[1]), $points, array_keys($points)));
    $last = $points[$count - 1] ?? [0, 0];
    $area = sprintf('%s L%.1F %d L%.1F %d Z', $line, $last[0], $height, $points[0][0] ?? 0, $height);
    $color = "var(--series-{$series})";
@endphp

@if ($count > 1)
    <svg width="{{ $width }}" height="{{ $height }}" viewBox="0 0 {{ $width }} {{ $height }}" class="overflow-visible" aria-hidden="true">
        <defs>
            <linearGradient id="{{ $id }}" x1="0" x2="0" y1="0" y2="1">
                <stop offset="0%" stop-color="{{ $color }}" stop-opacity="0.22" />
                <stop offset="100%" stop-color="{{ $color }}" stop-opacity="0" />
            </linearGradient>
        </defs>
        <path d="{{ $area }}" fill="url(#{{ $id }})" />
        <path d="{{ $line }}" fill="none" stroke="{{ $color }}" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" />
        <circle cx="{{ $last[0] }}" cy="{{ $last[1] }}" r="2.5" fill="{{ $color }}" />
    </svg>
@endif
