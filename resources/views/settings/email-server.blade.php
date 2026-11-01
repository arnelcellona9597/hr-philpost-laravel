@php
    $when = fn ($time): string => $time ? $time->timezone('Asia/Manila')->format('M j, Y g:i A') : 'never';
    $security = old('security', $settings->security);
    $fieldError = fn (string $name) => $errors->first($name);
@endphp

<x-layouts.app title="Email server" heading="Email server (SMTP)" description="Used for sign-in codes, invitations, password resets, payslips and all notifications. Stored in the database; the password is encrypted and never shown again." :back="['href' => route('settings.notifications'), 'label' => 'Email settings']">
    @if ($errors->has('form'))
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first('form') }}</div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <form method="post" action="{{ route('settings.email-server') }}" class="card grid gap-4 p-5 sm:grid-cols-2 xl:col-span-2" autocomplete="off" data-dirty-guard>
            @csrf
            @method('PUT')
            <div>
                <label class="label" for="host">Host</label>
                <input class="input" id="host" name="host" required value="{{ old('host', $settings->host) }}" placeholder="mail.yourdomain.com" />
                @if ($fieldError('host'))
                    <p class="mt-1 text-xs text-danger">{{ $fieldError('host') }}</p>
                @endif
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="port">Port</label>
                    <input class="input" id="port" name="port" type="number" min="1" max="65535" required value="{{ old('port', $settings->port) }}" />
                    @if ($fieldError('port'))
                        <p class="mt-1 text-xs text-danger">{{ $fieldError('port') }}</p>
                    @endif
                </div>
                <div>
                    <label class="label" for="security">Security</label>
                    <select class="input" id="security" name="security">
                        <option value="ssl" @selected($security === 'ssl')>SSL/TLS (SMTPS)</option>
                        <option value="starttls" @selected($security === 'starttls')>STARTTLS</option>
                        <option value="none" @selected($security === 'none')>None</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="label" for="username">Username</label>
                <input class="input" id="username" name="username" value="{{ old('username', $settings->username) }}" autocomplete="off" />
            </div>
            <div>
                <label class="label" for="password">Password</label>
                <input class="input" id="password" name="password" type="password" autocomplete="new-password" placeholder="{{ $settings->password_set ? '•••••••• (saved — leave blank to keep)' : 'Not set' }}" />
                @if ($settings->password_set)
                    <label class="mt-1 flex items-center gap-2 text-xs text-muted"><input type="checkbox" name="clear_password" value="1" class="size-3.5" /> Remove the saved password</label>
                @endif
            </div>
            <div>
                <label class="label" for="from_address">From address</label>
                <input class="input" id="from_address" name="from_address" type="email" required value="{{ old('from_address', $settings->from_address) }}" placeholder="hr@yourdomain.com" />
                @if ($fieldError('from_address'))
                    <p class="mt-1 text-xs text-danger">{{ $fieldError('from_address') }}</p>
                @endif
            </div>
            <div>
                <label class="label" for="from_name">From name</label>
                <input class="input" id="from_name" name="from_name" value="{{ old('from_name', $settings->from_name) }}" />
            </div>
            <div class="sm:col-span-2"><button class="btn-primary">Save and test connection</button></div>
        </form>
        <aside class="space-y-4">
            <section class="card space-y-2 p-5 text-sm">
                <h2 class="font-semibold">Status</h2>
                <p>Password: <strong>{{ $settings->password_set ? 'saved (encrypted)' : 'not set' }}</strong></p>
                <p>Last successful send: {{ $when($status->smtp_last_ok_at) }}</p>
                @if ($status->smtp_last_error)
                    <p class="rounded-lg bg-danger-soft px-3 py-2 text-danger">Last error ({{ $when($status->smtp_last_error_at) }}): {{ $status->smtp_last_error }}</p>
                @endif
            </section>
            <form method="post" action="{{ route('settings.email-server.test') }}" class="card space-y-2 p-5 text-sm">
                @csrf
                <h2 class="font-semibold">Send a test email</h2>
                <input class="input" name="to" type="email" required value="{{ auth()->user()->email }}" aria-label="Test recipient" />
                <button class="btn-secondary">Send test</button>
            </form>
        </aside>
    </div>
</x-layouts.app>
