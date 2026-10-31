@php
    $notices = [
        'expired' => ['warning', 'Your session ended (sessions last 12 hours). Please sign in again.'],
        'restart' => ['warning', 'The verification code expired or was entered wrong too many times. Please sign in again.'],
        'password-set' => ['success', 'Password saved. Sign in with your new password.'],
        'no-signup' => ['primary', 'Accounts are created by a System Admin. Ask yours for an invitation.'],
        'password' => ['success', 'Password changed. Please sign in again.'],
    ];
    $tone = $notices[$notice][0] ?? null;
@endphp

<x-layouts.auth title="Sign in" subtitle="HR staff access only.">
    @if ($tone)
        <p @class([
            'mb-4 rounded-lg px-3 py-2 text-sm',
            'bg-warning-soft text-warning' => $tone === 'warning',
            'bg-success-soft text-success' => $tone === 'success',
            'bg-primary-soft text-primary-ink' => $tone === 'primary',
        ]) role="status">{{ $notices[$notice][1] }}</p>
    @endif
    @error('email')
        <p class="mb-4 rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger" role="alert">{{ $message }}</p>
    @enderror
    @error('password')
        <p class="mb-4 rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger" role="alert">{{ $message }}</p>
    @enderror
    <form method="post" action="{{ route('login') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="next" value="{{ old('next', $next) }}" />
        <div>
            <label class="label" for="email">Email</label>
            <input class="input" id="email" name="email" type="email" autocomplete="username" required value="{{ old('email') }}" autofocus />
        </div>
        <div>
            <div class="flex items-center justify-between">
                <label class="label" for="password">Password</label>
                <a href="{{ route('password.forgot') }}" class="mb-1 text-xs text-primary-ink hover:underline">Forgot password?</a>
            </div>
            <input class="input" id="password" name="password" type="password" autocomplete="current-password" required />
        </div>
        <button class="btn-primary w-full">Sign in</button>
    </form>
    <x-slot:footer>New HR staff? Ask your System Admin to create your account.</x-slot:footer>
</x-layouts.auth>
