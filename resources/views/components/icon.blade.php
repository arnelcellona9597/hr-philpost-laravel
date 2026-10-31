@props(['name', 'label' => null])

<svg
    {{ $attributes->merge(['class' => $attributes->has('class') ? '' : 'size-4'])->merge($label ? ['role' => 'img', 'aria-label' => $label] : ['aria-hidden' => 'true']) }}
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
><path d="{{ \App\Support\Icons::PATHS[$name] }}"></path></svg>
