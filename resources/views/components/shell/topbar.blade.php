{{-- Sticky top bar: sidebar toggle, breadcrumbs, employee search, theme, notifications, account menu. --}}
@props(['crumbs', 'unread' => 0, 'user', 'prefs', 'path'])

@php
    $search = $path === '/employees' ? (string) request()->query('q', '') : '';
@endphp

<header id="topbar" class="sticky top-0 z-30 border-b border-line bg-surface/85 backdrop-blur-md supports-[backdrop-filter]:bg-surface/75">
    <div class="tb-inner flex h-full items-center gap-1.5 px-3 sm:gap-2 sm:px-6 lg:px-8">
        <button type="button" class="btn-icon -ml-1 shrink-0 lg:hidden" data-sidebar-open aria-controls="sidebar" aria-expanded="false" aria-label="Open menu">
            <x-icon name="menu" class="size-5" />
        </button>
        <button type="button" class="btn-icon -ml-2 hidden shrink-0 lg:inline-flex" data-sidebar-toggle aria-controls="sidebar" aria-expanded="{{ $prefs['collapsed'] ? 'false' : 'true' }}" title="Toggle sidebar">
            <x-icon :name="$prefs['right'] ? 'panelRight' : 'panelLeft'" class="size-[18px]" />
            <span class="sr-only" data-toggle-label>{{ $prefs['collapsed'] ? 'Expand sidebar' : 'Collapse sidebar' }}</span>
        </button>
        <span class="tb-divider mx-1 hidden h-5 w-px bg-line lg:block" aria-hidden="true"></span>

        <nav aria-label="Breadcrumb" class="min-w-0 flex-1">
            <ol class="flex min-w-0 items-center gap-1 text-sm">
                @foreach ($crumbs as $crumb)
                    @if ($loop->last)
                        <li class="tb-title min-w-0 truncate font-semibold text-fg" aria-current="page">{{ $crumb['label'] }}</li>
                    @else
                        <li class="crumb-trail hidden shrink-0 items-center gap-1 sm:flex">
                            <a href="{{ url($crumb['href']) }}" class="rounded px-1 text-muted transition-colors hover:text-fg">
                                @if ($loop->first)
                                    <x-icon name="home" class="size-4" />
                                    <span class="sr-only">{{ $crumb['label'] }}</span>
                                @else
                                    {{ $crumb['label'] }}
                                @endif
                            </a>
                            <x-icon name="chevronRight" class="size-3.5 text-line-strong" />
                        </li>
                    @endif
                @endforeach
            </ol>
        </nav>

        <form method="get" action="{{ url('/employees') }}" role="search" class="tb-search relative hidden md:block" data-topbar-search>
            <label for="topbar-q" class="sr-only">Search employees</label>
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
            <input
                id="topbar-q"
                name="q"
                type="search"
                value="{{ $search }}"
                placeholder="Search employees…"
                autocomplete="off"
                class="h-9 w-56 rounded-lg border border-line bg-surface-2 pl-9 pr-9 text-sm text-fg transition-[width,border-color,background-color,box-shadow] duration-150 placeholder:text-muted hover:border-line-strong focus:w-72 focus:border-primary-ink focus:bg-surface focus:outline-none focus:ring-3 focus:ring-primary-soft xl:w-64"
            />
            <span class="kbd pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2" aria-hidden="true">/</span>
        </form>
        <a href="{{ url('/employees#search') }}" class="tb-search btn-icon md:hidden" aria-label="Search employees">
            <x-icon name="search" class="size-[18px]" />
        </a>

        <button type="button" class="tb-theme btn-icon" data-theme-toggle title="Toggle dark mode">
            <x-icon name="moon" class="size-[18px] dark:hidden" />
            <x-icon name="sun" class="hidden size-[18px] dark:block" />
            <span class="sr-only dark:hidden">Switch to dark mode</span>
            <span class="sr-only hidden dark:inline">Switch to light mode</span>
        </button>

        <button type="button" class="tb-compact-toggle btn-icon hidden md:inline-flex" data-header-toggle aria-pressed="{{ $prefs['compact'] ? 'true' : 'false' }}" title="{{ $prefs['compact'] ? 'Expand header' : 'Compact header' }}">
            <x-icon name="panelTop" class="size-[18px]" />
            <span class="sr-only">Compact header</span>
        </button>

        <a href="{{ url('/notifications') }}" class="btn-icon relative" aria-label="Notifications{{ $unread ? " ({$unread} unread)" : '' }}" title="Notifications">
            <x-icon name="bell" class="size-[18px]" />
            @if ($unread > 0)
                <span class="tb-badge absolute right-1 top-1 flex min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold leading-4 text-white ring-2 ring-surface tabular">
                    {{ $unread > 99 ? '99+' : $unread }}
                </span>
            @endif
        </a>

        <span class="tb-divider mx-1 hidden h-5 w-px bg-line sm:block" aria-hidden="true"></span>
        <x-shell.user-menu :user="$user" :prefs="$prefs" />
    </div>
</header>
