<x-layouts.app title="Notifications">
    <x-slot:actions>
        <form method="post" action="{{ route('notifications.read-all') }}">@csrf<button class="btn-secondary">Mark all as read</button></form>
    </x-slot:actions>
    <div class="card overflow-hidden">
        @if ($notifications->total() === 0)
            <x-empty-state title="No notifications" text="Leave filings and other system events will show up here." />
        @else
            <ul class="divide-y divide-line">
                @foreach ($notifications as $notification)
                    <li @class(['flex items-start gap-3 px-4 py-3', 'bg-primary-soft' => ! $notification->read_at])>
                        <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-transparent' => $notification->read_at, 'bg-primary' => ! $notification->read_at]) aria-hidden="true"></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ $notification->title }}</p>
                            @if ($notification->body)
                                <p class="text-sm text-fg-2">{{ $notification->body }}</p>
                            @endif
                            <p class="mt-0.5 text-xs text-muted">{{ $notification->created_at->format('M j, Y g:i A') }}</p>
                        </div>
                        <form method="post" action="{{ route('notifications.read', $notification) }}">
                            @csrf
                            <button class="btn-secondary btn-sm">{{ $notification->link ? 'Open' : 'Mark read' }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>
            <x-pagination :paginator="$notifications" />
        @endif
    </div>
</x-layouts.app>
