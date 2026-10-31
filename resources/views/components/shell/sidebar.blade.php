{{-- Main navigation. Desktop: sticky, expanded or collapsed. Below lg: an off-canvas drawer. Behaviour is in resources/js/shell.js. --}}
@props(['groups', 'path', 'company', 'logoId' => null, 'sessionEnds' => null, 'sessionExpiresAt' => null])

<aside id="sidebar" class="flex flex-col bg-surface" aria-label="Sidebar" data-open="false">
    <div class="sidebar-brand flex shrink-0 items-center gap-3 border-b border-line px-4" style="height: var(--topbar-h)">
        <a href="{{ url('/') }}" class="flex min-w-0 flex-1 items-center gap-3 rounded-lg" data-tip="{{ $company }}">
            @if ($logoId)
                <img src="{{ url("/files/{$logoId}") }}" alt="" class="size-9 shrink-0 rounded-lg object-contain" />
            @else
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-sm font-bold tracking-tight text-on-primary shadow-elev-1">
                    {{ mb_strtoupper(mb_substr($company, 0, 2)) }}
                </span>
            @endif
            <span class="sidebar-label min-w-0">
                <span class="block truncate text-sm font-semibold leading-tight">{{ $company }}</span>
                <span class="block truncate text-xs text-muted">Human Resource Management</span>
            </span>
        </a>
        <button type="button" class="btn-icon shrink-0 lg:hidden" data-sidebar-close aria-label="Close menu">
            <x-icon name="x" class="size-5" />
        </button>
    </div>

    <nav class="scroll-thin flex-1 overflow-y-auto overflow-x-hidden px-3 py-4" aria-label="Main">
        @foreach ($groups as $group)
            @if ($group['items'])
                <div @class(['mt-6' => ! $loop->first])>
                    <p class="sidebar-heading mb-2 flex items-center px-3" role="presentation">
                        <span class="eyebrow">{{ $group['label'] }}</span>
                    </p>
                    <ul class="space-y-0.5">
                        @foreach ($group['items'] as $item)
                            <li>
                                <a href="{{ url($item['href']) }}" class="nav-link" data-tip="{{ $item['label'] }}" @if (\App\Support\Navigation::isActive($item['href'], $path)) aria-current="page" @endif>
                                    <x-icon :name="$item['icon']" class="size-[18px] shrink-0" />
                                    <span class="sidebar-label truncate">{{ $item['label'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach
    </nav>

    <div class="shrink-0 space-y-1 border-t border-line p-3">
        @if ($sessionEnds)
            <p class="side-row flex h-8 items-center gap-2 rounded-lg px-3 text-xs text-muted" data-session-expires="{{ $sessionExpiresAt }}" data-tip="Signed in until {{ $sessionEnds }}" title="Sessions last 12 hours">
                <x-icon name="clock" class="size-4 shrink-0" />
                <span class="sidebar-label">Signed in until {{ $sessionEnds }}</span>
            </p>
        @endif
        <button type="button" class="nav-link hidden w-full lg:flex" data-sidebar-toggle data-tip="Expand sidebar" aria-expanded="true" aria-controls="sidebar">
            <x-icon name="chevronsLeft" class="collapse-icon size-[18px] shrink-0 transition-transform duration-200" />
            <span class="sidebar-label" data-toggle-label>Collapse sidebar</span>
        </button>
    </div>
</aside>
<div id="sidebar-backdrop" class="fixed inset-0 z-40 bg-black/45 backdrop-blur-[2px] lg:hidden" data-open="false" aria-hidden="true"></div>
<div id="nav-tip" class="rounded-md bg-fg px-2 py-1 text-xs font-medium text-bg shadow-pop" aria-hidden="true"></div>
