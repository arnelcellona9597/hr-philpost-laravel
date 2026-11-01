@php
    use App\Enums\MessageStatus;
    use App\Enums\RecipientStatus;

    $pending = in_array($message->status, [MessageStatus::Queued, MessageStatus::Sending], true);
    $statusTone = match ($message->status) {
        MessageStatus::Sent => 'badge-success',
        MessageStatus::Partial => 'badge-warning',
        MessageStatus::Failed => 'badge-danger',
        MessageStatus::Sending => 'badge-primary',
        default => 'badge-neutral',
    };
    $rowTone = fn (RecipientStatus $status): string => match ($status) {
        RecipientStatus::Sent => 'badge-success',
        RecipientStatus::Failed => 'badge-danger',
        RecipientStatus::Skipped => 'badge-warning',
        RecipientStatus::Sending => 'badge-primary',
        default => 'badge-neutral',
    };
    $filters = [null, RecipientStatus::Sent, RecipientStatus::Queued, RecipientStatus::Failed, RecipientStatus::Skipped];
@endphp

<x-layouts.app :title="$message->subject" :heading="$message->subject" :back="['href' => route('messages.index'), 'label' => 'Messages']">
    <x-slot:actions>
        @if ($pending)
            <form method="post" action="{{ route('messages.process', $message) }}">@csrf<button class="btn-secondary"><x-icon name="send" /> Send queued now</button></form>
        @endif
        @if ($message->failed > 0)
            <form method="post" action="{{ route('messages.retry', $message) }}">@csrf<button class="btn-primary">Retry {{ $message->failed }} failed</button></form>
        @endif
    </x-slot:actions>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="card space-y-3 p-5 text-sm xl:col-span-1">
            <dl class="grid grid-cols-3 gap-2">
                <dt class="text-muted">Status</dt><dd class="col-span-2"><span class="{{ $statusTone }}">{{ $message->status->value }}</span></dd>
                <dt class="text-muted">Category</dt><dd class="col-span-2">{{ $message->category->value }}{{ $message->automatic ? ' (automatic)' : '' }}</dd>
                <dt class="text-muted">To</dt><dd class="col-span-2">{{ $message->audience }}</dd>
                <dt class="text-muted">Delivered</dt>
                <dd class="col-span-2 tabular">{{ $message->sent }} of {{ $message->total - $message->skipped }}{{ $message->failed ? ", {$message->failed} failed" : '' }}{{ $message->skipped ? ", {$message->skipped} skipped" : '' }}</dd>
                <dt class="text-muted">Sent by</dt><dd class="col-span-2">{{ $message->sent_by_name }}</dd>
                <dt class="text-muted">Created</dt><dd class="col-span-2">{{ $message->created_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}</dd>
            </dl>
            @if ($message->attachments->isNotEmpty())
                <div>
                    <p class="text-muted">Attachments</p>
                    @foreach ($message->attachments as $file)
                        <a class="block text-primary-ink hover:underline" href="{{ route('files.show', $file) }}">{{ $file->filename }}</a>
                    @endforeach
                </div>
            @endif
            <div class="rounded-lg bg-surface-2 p-3"><p class="whitespace-pre-wrap">{{ $message->body }}</p></div>
            @if ($pending)
                <p class="text-xs text-muted">Emails go out in batches within the hourly limit set in Email Settings.</p>
            @endif
        </section>
        <section class="card overflow-hidden xl:col-span-2">
            <header class="flex flex-wrap items-center justify-between gap-2 p-4">
                <h2 class="text-sm font-semibold">Recipients</h2>
                <div class="flex gap-1 text-xs">
                    @foreach ($filters as $filter)
                        <a href="{{ route('messages.show', [$message, ...($filter ? ['status' => $filter->value] : [])]) }}" @class(['rounded-md px-2 py-1', 'bg-primary-soft text-primary-ink' => $status === $filter, 'text-fg-2 hover:bg-surface-2' => $status !== $filter])>{{ $filter?->value ?? 'all' }}</a>
                    @endforeach
                </div>
            </header>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Sent</th><th>Note</th></tr></thead>
                    <tbody>
                        @foreach ($recipients as $recipient)
                            <tr>
                                <td class="font-medium">{{ $recipient->name }}</td>
                                <td class="text-fg-2">{{ $recipient->email ?? '—' }}</td>
                                <td><span class="{{ $rowTone($recipient->status) }}">{{ $recipient->status->value }}</span></td>
                                <td class="whitespace-nowrap tabular text-fg-2">{{ $recipient->sent_at?->timezone('Asia/Manila')->format('M j, g:i A') }}</td>
                                <td class="max-w-64 truncate text-xs text-fg-2" title="{{ $recipient->error }}">{{ $recipient->error }}{{ $recipient->attempts ? " (attempt {$recipient->attempts})" : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$recipients" />
        </section>
    </div>
</x-layouts.app>
