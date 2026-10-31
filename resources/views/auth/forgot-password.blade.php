<x-layouts.auth title="Reset your password" subtitle="We'll email you a link to set a new password.">
    @if (session('sent'))
        <p class="rounded-lg bg-success-soft px-3 py-2 text-sm text-success" role="status">
            If that email belongs to an account, a reset link is on its way. The link is valid for 1 hour.
        </p>
    @else
        <form method="post" action="{{ route('password.forgot') }}" class="space-y-4">
            @csrf
            @unless ($emailEnabled)
                <p class="rounded-lg bg-warning-soft px-3 py-2 text-sm text-warning">
                    Email sending is not configured on this server. Ask a System Admin to reset your password.
                </p>
            @endunless
            <div>
                <label class="label" for="email">Email</label>
                <input class="input" id="email" name="email" type="email" required autocomplete="username" />
            </div>
            <button class="btn-primary w-full">Send reset link</button>
        </form>
    @endif
    <x-slot:footer><a href="{{ route('login') }}" class="font-medium text-primary-ink hover:underline">Back to sign in</a></x-slot:footer>
</x-layouts.auth>
