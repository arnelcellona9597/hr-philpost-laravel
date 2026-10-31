@php
    $isInvite = ($link['kind'] ?? null) === \App\Enums\LoginChallengePurpose::Invite;
@endphp

<x-layouts.auth :title="$isInvite ? 'Welcome — choose your password' : 'Choose a new password'" :subtitle="$link ? 'For '.$link['name'].' ('.$link['maskedEmail'].')' : null">
    @error('password')
        <p class="mb-4 rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger" role="alert">{{ $message }}</p>
    @enderror
    @if ($link)
        <form method="post" action="{{ route('password.set') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}" />
            <div>
                <label class="label" for="password">New password</label>
                <input class="input" id="password" name="password" type="password" autocomplete="new-password" required minlength="{{ \App\Rules\HrPassword::MIN_LENGTH }}" />
                <p class="field-help">At least {{ \App\Rules\HrPassword::MIN_LENGTH }} characters, with letters and numbers.</p>
            </div>
            <div>
                <label class="label" for="password_confirmation">Confirm password</label>
                <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required />
            </div>
            <button class="btn-primary w-full">Save password</button>
        </form>
    @else
        <p class="text-sm text-muted">This link is invalid, was already used or has expired. Ask your System Admin to send a new one, or use “Forgot password?” on the sign-in page.</p>
    @endif
    <x-slot:footer><a href="{{ route('login') }}" class="font-medium text-primary-ink hover:underline">Back to sign in</a></x-slot:footer>
</x-layouts.auth>
