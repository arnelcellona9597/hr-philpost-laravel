@props(['title', 'text' => null])

<div class="px-6 py-14 text-center">
    <p class="font-medium">{{ $title }}</p>
    @if ($text)
        <p class="mt-1 text-sm text-muted">{{ $text }}</p>
    @endif
    <div class="mt-4 flex justify-center gap-2">{{ $slot }}</div>
</div>
