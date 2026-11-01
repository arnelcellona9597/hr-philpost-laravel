@php
    use App\Enums\MessageStatus;

    $tone = fn (MessageStatus $status): string => match ($status) {
        MessageStatus::Sent => 'badge-success',
        MessageStatus::Partial => 'badge-warning',
        MessageStatus::Failed => 'badge-danger',
        MessageStatus::Sending => 'badge-primary',
        default => 'badge-neutral',
    };
@endphp

<x-layouts.app title="Messages" heading="Messages" description="Emails sent from the system — announcements, reminders, leave and payroll notices — and their delivery status.">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('settings.notifications') }}"><x-icon name="settings" /> Email settings &amp; templates</a>
        <a class="btn-primary" href="{{ route('messages.create') }}"><x-icon name="send" /> New message</a>
    </x-slot:actions>

    <form method="get" class="card mb-4 flex flex-wrap items-end gap-3 p-4" data-autosubmit>
        <div class="min-w-56 flex-1"><label class="label" for="q">Search</label><input class="input" id="q" name="q" type="search" value="{{ $search }}" placeholder="Subject, recipients or sender" /></div>
        <div>
            <label class="label" for="category">Category</label>
            <select class="input" id="category" name="category">
                <option value="">All</option>
                @foreach ($categories as $option)
                    <option @selected($option === $category)>{{ $option->value }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="kind">Type</label>
            <select class="input" id="kind" name="kind">
                <option value="">All</option>
                <option value="manual" @selected($kind === 'manual')>Written by HR</option>
                <option value="automatic" @selected($kind === 'automatic')>Automatic</option>
            </select>
        </div>
        <button class="btn-primary">Filter</button>
    </form>

    <div class="card overflow-hidden">
        @if ($messages->isEmpty())
            <x-empty-state title="No messages yet" text="Send an announcement, a reminder or a payroll notice.">
                <a class="btn-primary" href="{{ route('messages.create') }}">New message</a>
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Sent</th><th>Subject</th><th>Category</th><th>To</th><th class="text-right">Delivered</th><th>Status</th><th>By</th></tr></thead>
                    <tbody>
                        @foreach ($messages as $message)
                            <tr>
                                <td class="whitespace-nowrap tabular text-fg-2">{{ $message->created_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}</td>
                                <td class="font-medium"><a class="hover:text-primary-ink" href="{{ route('messages.show', $message) }}">{{ $message->subject }}</a></td>
                                <td>
                                    <span class="badge-neutral">{{ $message->category->value }}</span>
                                    @if ($message->automatic)
                                        <span class="ml-1 text-xs text-muted">auto</span>
                                    @endif
                                </td>
                                <td class="max-w-64 truncate text-fg-2" title="{{ $message->audience }}">{{ $message->audience }}</td>
                                <td class="text-right tabular">
                                    {{ $message->sent }}/{{ $message->total - $message->skipped }}
                                    @if ($message->failed)
                                        <span class="text-danger"> · {{ $message->failed }} failed</span>
                                    @endif
                                </td>
                                <td><span class="{{ $tone($message->status) }}">{{ $message->status->value }}</span></td>
                                <td class="text-fg-2">{{ $message->sent_by_name }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$messages" />
        @endif
    </div>
</x-layouts.app>
