@props(['name', 'imageId' => null])

@php
    $size = $attributes->has('class') ? '' : 'size-8 text-xs';
@endphp

@if ($imageId)
    <img src="{{ url("/files/{$imageId}") }}" alt="" {{ $attributes->class(['shrink-0 rounded-full bg-surface-2 object-cover', $size]) }} loading="lazy" decoding="async" />
@else
    <span {{ $attributes->class(['flex shrink-0 items-center justify-center rounded-full bg-primary font-semibold text-on-primary', $size]) }} aria-hidden="true">
        {{ \App\Support\Names::initials($name) }}
    </span>
@endif
