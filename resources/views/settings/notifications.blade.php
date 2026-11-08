@php
    $when = fn ($time): string => $time ? $time->timezone('Asia/Manila')->format('M j, Y g:i A') : 'never';
    $fieldError = fn (string $name) => $errors->first($name);
@endphp

<x-layouts.app title="Email Settings" heading="Email & notifications" description="Automatic emails, sending limits, templates and the mail server status.">
    <div class="grid gap-6 xl:grid-cols-3">
        <form method="post" action="{{ route('settings.notifications') }}" class="card space-y-5 p-5 xl:col-span-2" data-dirty-guard>
            @csrf
            @method('PUT')
            <fieldset class="space-y-2">
                <legend class="mb-2 font-semibold">Automatic emails</legend>
                @foreach ($switches as $name => $label)
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="{{ $name }}" value="1" @checked($settings->{$name}) class="size-4" /> {{ $label }}</label>
                @endforeach
                <p class="field-help">Emails go to the address on the employee's record; employees without one are skipped and listed in Messages.</p>
            </fieldset>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="hourly_limit">Maximum emails per hour</label>
                    <input class="input" id="hourly_limit" name="hourly_limit" type="number" min="1" max="5000" required value="{{ old('hourly_limit', $settings->hourly_limit) }}" />
                    @if ($fieldError('hourly_limit'))
                        <p class="mt-1 text-xs text-danger">{{ $fieldError('hourly_limit') }}</p>
                    @endif
                    <p class="field-help">Ask your host for the limit (often 100–500/hour). Extra emails wait in the queue.</p>
                </div>
                <div>
                    <label class="label" for="reply_to">Reply-to address</label>
                    <input class="input" id="reply_to" name="reply_to" type="email" value="{{ old('reply_to', $settings->reply_to) }}" placeholder="hr@yourdomain.com" />
                    @if ($fieldError('reply_to'))
                        <p class="mt-1 text-xs text-danger">{{ $fieldError('reply_to') }}</p>
                    @endif
                </div>
            </div>
            <div>
                <label class="label" for="footer">Email footer</label>
                <textarea class="input min-h-20" id="footer" name="footer">{{ old('footer', $settings->footer) }}</textarea>
            </div>
            <button class="btn-primary">Save settings</button>
        </form>

        <aside class="space-y-4">
            <section class="card space-y-3 p-5 text-sm">
                <h2 class="font-semibold">Mail server</h2>
                @if ($configured)
                    <p @class(['rounded-lg px-3 py-2', 'bg-danger-soft text-danger' => $settings->smtp_last_error, 'bg-success-soft text-success' => ! $settings->smtp_last_error])>
                        @if ($settings->smtp_last_error)
                            {{ $isAdmin ? 'Last error ('.$when($settings->smtp_last_error_at).'): '.$settings->smtp_last_error : 'There is a problem sending email. Please tell your System Admin.' }}
                        @else
                            Working. Last successful send: {{ $when($settings->smtp_last_ok_at) }}.
                        @endif
                    </p>
                @else
                    <p class="rounded-lg bg-warning-soft px-3 py-2 text-warning">The email server is not set up yet{{ $isAdmin ? '' : ' — ask your System Admin' }}. No emails are sent.</p>
                @endif
                @if ($isAdmin)
                    <a class="btn-secondary btn-sm" href="{{ route('settings.email-server') }}">Email server (SMTP) settings</a>
                @endif
                <p class="text-fg-2">Emails waiting in the queue: <strong class="tabular">{{ $queued }}</strong></p>
                <form method="post" action="{{ route('settings.notifications.test') }}">
                    @csrf
                    <button class="btn-secondary btn-sm">Send a test email to me ({{ auth()->user()->email }})</button>
                </form>
            </section>
            @if ($isAdmin)
                <section class="card space-y-2 p-5 text-sm">
                    <h2 class="font-semibold">Cron job</h2>
                    <p class="text-fg-2">Run the scheduler every minute. If the host's cron can only call a web address, call this instead (every 5 minutes). Keep the key secret.</p>
                    <code class="block break-all rounded-lg bg-surface-2 p-2 font-mono text-xs">php artisan schedule:run</code>
                    <code class="block break-all rounded-lg bg-surface-2 p-2 font-mono text-xs">curl -s -H "X-Queue-Key: {{ $settings->queue_key }}" "{{ route('internal.queue') }}" &gt; /dev/null</code>
                    <form method="post" action="{{ route('settings.notifications.rotate') }}" data-confirm="Create a new key? The old cron command will stop working.">
                        @csrf
                        <button class="btn-ghost btn-sm">Rotate key</button>
                    </form>
                </section>
            @endif
        </aside>
    </div>

    <section class="card mt-6 overflow-hidden">
        <header class="flex items-center justify-between p-4">
            <h2 class="text-sm font-semibold">Email templates</h2>
            <a class="btn-primary btn-sm" href="{{ route('settings.templates.create') }}"><x-icon name="plus" class="size-3.5" /> New template</a>
        </header>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Name</th><th>Category</th><th>Used for</th><th>Subject</th></tr></thead>
                <tbody>
                    @foreach ($templates as $template)
                        <tr>
                            <td class="font-medium"><a class="hover:text-primary-ink" href="{{ route('settings.templates.edit', $template) }}">{{ $template->name }}</a></td>
                            <td><span class="badge-neutral">{{ $template->category->value }}</span></td>
                            <td class="text-fg-2">{{ $template->key ? 'Automatic emails' : 'Manual messages' }}</td>
                            <td class="max-w-md truncate text-fg-2">{{ $template->subject }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
