{{-- Account menu: who is signed in, account links, display preferences and Log out. Keyboard handling is in resources/js/shell.js. --}}
@props(['user', 'prefs'])

@php
    $name = $user?->name ?? '';
    $roleLabel = $user?->role?->label() ?? '';
    $links = array_values(array_filter([
        ['href' => '/profile', 'label' => 'My profile & picture', 'icon' => 'user'],
        $user?->isSystemAdmin() ? ['href' => '/users', 'label' => 'HR accounts', 'icon' => 'shield'] : null,
        $user?->isSystemAdmin() ? ['href' => '/settings/email-server', 'label' => 'Email server', 'icon' => 'mail'] : null,
        ['href' => '/settings', 'label' => 'Branding & theme', 'icon' => 'settings'],
    ]));
    $toggles = [
        ['pref' => 'collapsed', 'label' => 'Collapsed sidebar', 'icon' => 'panelLeft', 'desktopOnly' => true],
        ['pref' => 'right', 'label' => 'Sidebar on the right', 'icon' => 'panelRight', 'desktopOnly' => false],
        ['pref' => 'compact', 'label' => 'Compact header', 'icon' => 'panelTop', 'desktopOnly' => false],
    ];
@endphp

<div class="relative" data-user-menu>
    <button
        type="button"
        id="user-menu-button"
        class="tb-user flex items-center gap-2.5 rounded-full py-1 pl-1 pr-1 transition-colors hover:bg-surface-2 sm:rounded-lg sm:pr-2"
        aria-haspopup="menu"
        aria-expanded="false"
        aria-controls="user-menu"
        aria-label="Account menu for {{ $name }}"
    >
        <x-avatar :name="$name" :image-id="$user?->avatar_id" class="tb-avatar size-8 text-xs ring-2 ring-surface" />
        <span class="tb-user-text hidden min-w-0 text-left leading-tight md:block">
            <span class="block max-w-40 truncate text-sm font-medium text-fg">{{ $name }}</span>
            <span class="block max-w-40 truncate text-xs text-muted">{{ $roleLabel }}</span>
        </span>
        <x-icon name="chevronDown" class="tb-user-text hidden size-4 text-muted md:block" />
    </button>

    <div id="user-menu" class="menu-panel absolute right-0 top-full z-50 mt-2 w-72 rounded-xl border border-line bg-surface p-1.5 shadow-pop" role="menu" aria-labelledby="user-menu-button" hidden>
        <div class="flex items-center gap-3 px-2.5 pb-3 pt-2" role="none">
            <x-avatar :name="$name" :image-id="$user?->avatar_id" class="size-10 text-sm" />
            <span class="min-w-0">
                <span class="block truncate text-sm font-semibold">{{ $name }}</span>
                <span class="block truncate text-xs text-muted">{{ $user?->email }}</span>
                @if ($roleLabel !== '')
                    <span class="badge-primary mt-1">{{ $roleLabel }}</span>
                @endif
            </span>
        </div>
        <div class="my-1 h-px bg-line" role="separator"></div>
        @foreach ($links as $link)
            <a href="{{ url($link['href']) }}" class="menu-item" role="menuitem" tabindex="-1">
                <x-icon :name="$link['icon']" class="size-4 shrink-0 text-muted" />
                {{ $link['label'] }}
            </a>
        @endforeach
        <div class="my-1 h-px bg-line" role="separator"></div>
        <p class="eyebrow px-2.5 pb-1 pt-2" role="presentation">Display</p>
        <button type="button" class="menu-item" role="menuitemcheckbox" aria-checked="false" data-pref="theme" tabindex="-1">
            <x-icon name="moon" class="size-4 shrink-0 text-muted" />
            <span class="flex-1">Dark mode</span>
            <span class="pref-switch" aria-hidden="true"></span>
        </button>
        @foreach ($toggles as $toggle)
            <button type="button" @class(['menu-item', 'max-lg:hidden' => $toggle['desktopOnly']]) role="menuitemcheckbox" aria-checked="{{ $prefs[$toggle['pref']] ? 'true' : 'false' }}" data-pref="{{ $toggle['pref'] }}" tabindex="-1">
                <x-icon :name="$toggle['icon']" class="size-4 shrink-0 text-muted" />
                <span class="flex-1">{{ $toggle['label'] }}</span>
                <span class="pref-switch" aria-hidden="true"></span>
            </button>
        @endforeach
        <div class="my-1 h-px bg-line" role="separator"></div>
        <form method="post" action="{{ url('/logout') }}" role="none">
            @csrf
            <button class="menu-item menu-item-danger" role="menuitem" tabindex="-1">
                <x-icon name="logout" class="size-4 shrink-0" />
                Log out
            </button>
        </form>
    </div>
</div>
