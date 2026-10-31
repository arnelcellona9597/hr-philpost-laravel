<x-layouts.auth title="Check your email" subtitle="Two-step verification">
    <x-auth.code-form
        :masked-email="$challenge['maskedEmail']"
        :resend-available-at="$challenge['resendAvailableAt']"
        :sends-left="$challenge['sendsLeft']"
        :error="$errors->first('code') ?: null"
        :info="session('status')"
    />
</x-layouts.auth>
