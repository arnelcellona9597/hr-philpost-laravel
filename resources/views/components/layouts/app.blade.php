@props([
    'title',
    'heading' => null,
    'description' => null,
    'back' => null,
    'actions' => null,
])

@php
    use App\Support\Navigation;

    $flash = session('flash');
    $sessionEnds = $sessionExpiresAt?->timezone('Asia/Manila')->format('g:i A');
    $isSystemAdmin = $user?->isSystemAdmin() ?? false;
@endphp

<x-layouts.base :title="$title">
    <a href="#content" class="sr-only z-[70] rounded-lg bg-surface px-4 py-2 text-sm font-medium shadow-pop focus:not-sr-only focus:fixed focus:left-4 focus:top-4">Skip to content</a>
    <div
        id="shell"
        data-sidebar="{{ $prefs['collapsed'] ? 'collapsed' : 'expanded' }}"
        data-side="{{ $prefs['right'] ? 'right' : 'left' }}"
        data-header="{{ $prefs['compact'] ? 'compact' : 'full' }}"
    >
        <x-shell.sidebar :groups="$groups" :path="$path" :company="$company" :logo-id="$logoId" :session-ends="$sessionEnds" :session-expires-at="$sessionExpiresAt?->getTimestampMs()" />

        <div class="flex min-w-0 flex-1 flex-col">
            <x-shell.topbar :crumbs="Navigation::crumbs($path, $groups, $title)" :unread="$unreadNotifications" :user="$user" :prefs="$prefs" :path="$path" />

            <main id="content" tabindex="-1" class="mx-auto w-full max-w-[1440px] flex-1 px-4 py-6 outline-none sm:px-6 lg:px-8 lg:py-8">
                @if ($back)
                    <a href="{{ $back['href'] }}" class="group mb-3 inline-flex items-center gap-1.5 text-sm text-muted transition-colors hover:text-fg">
                        <x-icon name="arrowLeft" class="size-4 transition-transform group-hover:-translate-x-0.5" /> {{ $back['label'] }}
                    </a>
                @endif

                <div class="mb-6 flex flex-wrap items-end justify-between gap-4 lg:mb-8">
                    <div class="min-w-0">
                        <h1 class="text-2xl font-semibold tracking-tight text-fg sm:text-[1.75rem] sm:leading-9">{{ $heading ?? $title }}</h1>
                        @if ($description)
                            <p class="mt-1.5 max-w-3xl text-sm text-muted">{{ $description }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
                </div>

                @if ($mailWarning)
                    <div class="mb-4 flex items-start gap-3 rounded-xl border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">
                        <x-icon name="alert" class="mt-0.5 size-4 shrink-0" />
                        <span>
                            {{ $mailWarning }}
                            @if ($isSystemAdmin)
                                <a class="font-medium underline underline-offset-2" href="{{ url('/settings/email-server') }}">Open email server settings</a>
                            @endif
                        </span>
                    </div>
                @endif

                @if (request()->query('denied') === '1')
                    <div class="mb-4 flex items-start gap-3 rounded-xl border border-warning/30 bg-warning-soft px-4 py-3 text-sm text-warning" role="alert">
                        <x-icon name="alert" class="mt-0.5 size-4 shrink-0" /> You don't have permission to open that page.
                    </div>
                @endif

                @if ($flash)
                    <div
                        @class([
                            'mb-4 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm',
                            'border-success/25 bg-success-soft text-success' => $flash['type'] === 'success',
                            'border-danger/30 bg-danger-soft text-danger' => $flash['type'] === 'error',
                            'border-primary-ink/20 bg-primary-soft text-primary-ink' => $flash['type'] === 'info',
                        ])
                        role="{{ $flash['type'] === 'error' ? 'alert' : 'status' }}"
                    >
                        <x-icon :name="match ($flash['type']) { 'success' => 'checkCircle', 'error' => 'alert', default => 'info' }" class="mt-0.5 size-4 shrink-0" />
                        <span>{{ $flash['message'] }}</span>
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>
</x-layouts.base>
