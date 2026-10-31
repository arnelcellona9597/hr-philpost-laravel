{{-- The 6-digit email code step of signing in. --}}
@props(['maskedEmail', 'resendAvailableAt', 'sendsLeft', 'error' => null, 'info' => null])

@if ($error)
    <p class="mb-4 rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger" role="alert">{{ $error }}</p>
@endif
@if ($info)
    <p class="mb-4 rounded-lg bg-success-soft px-3 py-2 text-sm text-success" role="status">{{ $info }}</p>
@endif
<p class="mb-4 text-sm text-fg-2">We sent a 6-digit code to <strong class="text-fg">{{ $maskedEmail }}</strong>. It expires in 10 minutes.</p>
<form method="post" class="space-y-4">
    @csrf
    <input type="hidden" name="action" value="verify" />
    <div>
        <label class="label" for="code">Verification code</label>
        <input class="input text-center font-mono text-2xl tracking-[0.5em]" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus />
    </div>
    <button class="btn-primary w-full">Verify</button>
</form>
<div class="mt-4 flex items-center justify-between text-sm">
    <form method="post">
        @csrf
        <input type="hidden" name="action" value="resend" />
        <button class="text-primary-ink hover:underline disabled:text-muted disabled:no-underline" id="resend" data-at="{{ $resendAvailableAt }}" @disabled($sendsLeft <= 0)>
            Send a new code
        </button>
    </form>
    <form method="post">
        @csrf
        <input type="hidden" name="action" value="cancel" />
        <button class="text-muted hover:text-fg">Use another account</button>
    </form>
</div>
<p class="mt-3 text-xs text-muted">Didn't get it? Check your spam folder. You can request a new code every minute.</p>
