@props(['title', 'subtitle' => null, 'footer' => null])

<x-layouts.base :title="$title">
    <main class="flex min-h-dvh items-center justify-center px-4 py-10">
        <div class="w-full max-w-md">
            <div class="mb-6 flex flex-col items-center text-center">
                @if ($logoId)
                    <img src="{{ url("/files/{$logoId}") }}" alt="" class="mb-3 h-16 w-auto max-w-[200px] object-contain" />
                @else
                    <div class="mb-3 flex size-14 items-center justify-center rounded-2xl bg-primary text-xl font-bold text-on-primary">
                        {{ mb_strtoupper(mb_substr($company, 0, 2)) }}
                    </div>
                @endif
                <p class="text-sm font-semibold text-fg-2">{{ $company }}</p>
                @if ($tagline)
                    <p class="text-xs text-muted">{{ $tagline }}</p>
                @endif
            </div>
            <div class="card p-6 shadow-elev-2 sm:p-8">
                <h1 class="text-xl font-semibold">{{ $title }}</h1>
                @if ($subtitle)
                    <p class="mt-1 text-sm text-muted">{{ $subtitle }}</p>
                @endif
                <div class="mt-6">{{ $slot }}</div>
            </div>
            <div class="mt-6 text-center text-sm text-muted">{{ $footer }}</div>
        </div>
    </main>
</x-layouts.base>
