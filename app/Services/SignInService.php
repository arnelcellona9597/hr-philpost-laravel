<?php

namespace App\Services;

use App\Enums\LoginChallengePurpose;
use App\Enums\UserStatus;
use App\Exceptions\UserFacingException;
use App\Models\LoginChallenge;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Sign-in in two steps: the password, then a 6-digit code emailed to the account. The browser only
 * gets a session once the code is right.
 */
class SignInService
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    public const MAX_SENDS = 5;

    public const MAX_PASSWORD_FAILURES = 5;

    public const LOCK_MINUTES = 15;

    public function __construct(protected DirectMailer $mailer) {}

    /**
     * Step 1. Returns the key of a new challenge.
     *
     * @throws UserFacingException for a wrong password, a locked account or one that isn't approved
     */
    public function start(string $email, string $password, ?string $ip = null): string
    {
        LoginChallenge::query()->where('expires_at', '<', now())->delete();

        $user = User::query()->where('email', $email)->first();

        if ($user?->isLocked()) {
            throw new UserFacingException('Too many failed attempts. This account is locked for 15 minutes.');
        }

        if (! $this->passwordMatches($user, $password)) {
            throw new UserFacingException('Incorrect email or password.');
        }

        if ($user->status === UserStatus::Pending) {
            throw new UserFacingException('Your account is waiting for approval by a System Admin.');
        }

        if ($user->status !== UserStatus::Approved) {
            throw new UserFacingException('Your account has been disabled. Contact a System Admin.');
        }

        $user->challenges()->where('purpose', LoginChallengePurpose::Login)->delete();

        $key = bin2hex(random_bytes(24));
        $code = self::newCode();

        $user->challenges()->create([
            'key' => $key,
            'purpose' => LoginChallengePurpose::Login,
            'code_hash' => self::hashCode($key, $code),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'last_sent_at' => now(),
            'ip' => $ip,
        ]);

        $this->sendCode($user, $code);

        return $key;
    }

    /**
     * Whether the password is right, keeping count of failures towards the lockout.
     */
    public function passwordMatches(?User $user, string $password): bool
    {
        if ($user === null) {
            // Same work as a real check, so timing doesn't reveal whether the address exists.
            Hash::check($password, Hash::make('no-such-account'));

            return false;
        }

        if (! Hash::check($password, $user->password)) {
            $this->recordFailedPassword($user);

            return false;
        }

        $user->forceFill(['login_attempts' => 0, 'locked_until' => null])->saveQuietly();

        return true;
    }

    /**
     * What the code page shows, or null when the challenge has gone.
     *
     * @return array{maskedEmail: string, resendAvailableAt: string, sendsLeft: int}|null
     */
    public function info(?string $key): ?array
    {
        $challenge = $this->find($key);

        if ($challenge === null || $challenge->expires_at->isPast()) {
            return null;
        }

        return [
            'maskedEmail' => self::maskEmail($challenge->user->email),
            'resendAvailableAt' => $challenge->last_sent_at->addSeconds(self::RESEND_COOLDOWN_SECONDS)->toIso8601String(),
            'sendsLeft' => self::MAX_SENDS - $challenge->send_count,
        ];
    }

    /**
     * Step 2. Returns the signed-in user on success.
     *
     * @return array{user?: User, error?: string, restart?: bool}
     */
    public function verify(?string $key, string $code): array
    {
        $challenge = $this->find($key);

        if ($challenge === null || $challenge->expires_at->isPast()) {
            $challenge?->delete();

            return ['error' => 'This code has expired. Please sign in again.', 'restart' => true];
        }

        // Count the attempt atomically before comparing, so parallel guesses can't beat the limit.
        $counted = LoginChallenge::query()->whereKey($challenge->id)->where('attempts', '<', self::MAX_ATTEMPTS)->increment('attempts');

        if ($counted === 0) {
            $challenge->delete();

            return ['error' => 'Too many wrong codes. Please sign in again.', 'restart' => true];
        }

        if (! hash_equals($challenge->code_hash, self::hashCode($challenge->key, preg_replace('/\D/', '', $code)))) {
            $left = self::MAX_ATTEMPTS - $challenge->refresh()->attempts;

            if ($left <= 0) {
                $challenge->delete();

                return ['error' => 'Too many wrong codes. Please sign in again.', 'restart' => true];
            }

            return ['error' => "That code is not correct. {$left} attempt".($left === 1 ? '' : 's').' left.'];
        }

        // Single use: only the request that actually deletes the challenge gets the session.
        if (LoginChallenge::query()->whereKey($challenge->id)->delete() === 0 || ! $challenge->user->isApproved()) {
            return ['error' => 'Please sign in again.', 'restart' => true];
        }

        $challenge->user->forceFill(['email_verified_at' => $challenge->user->email_verified_at ?? now()])->saveQuietly();

        return ['user' => $challenge->user];
    }

    /**
     * @throws UserFacingException when a new code can't be sent yet
     */
    public function resend(?string $key): void
    {
        $challenge = $this->find($key);

        if ($challenge === null || $challenge->expires_at->isPast()) {
            throw new UserFacingException('This code has expired. Please sign in again.');
        }

        if ($challenge->send_count >= self::MAX_SENDS) {
            throw new UserFacingException('Too many codes sent. Please sign in again later.');
        }

        $wait = (int) ceil(now()->diffInSeconds($challenge->last_sent_at->addSeconds(self::RESEND_COOLDOWN_SECONDS), false));

        if ($wait > 0) {
            throw new UserFacingException("Please wait {$wait} seconds before requesting another code.");
        }

        $code = $this->replaceCode($challenge);
        $challenge->forceFill(['send_count' => $challenge->send_count + 1])->save();

        $this->sendCode($challenge->user, $code);
    }

    public function cancel(?string $key): void
    {
        $this->find($key)?->delete();
    }

    /**
     * Break-glass for when email is down: a new code for a sign-in already in progress. Only reachable
     * from the server's command line, and the code is printed rather than logged.
     */
    public function issueCodeFor(User $user): ?string
    {
        $challenge = $user->challenges()->where('purpose', LoginChallengePurpose::Login)->where('expires_at', '>', now())->first();

        return $challenge ? $this->replaceCode($challenge) : null;
    }

    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ($name === '' || $domain === '') {
            return $email;
        }

        return mb_substr($name, 0, 2).str_repeat('•', max(1, mb_strlen($name) - 2)).'@'.$domain;
    }

    private function find(?string $key): ?LoginChallenge
    {
        if ($key === null || ! preg_match('/^[a-f0-9]{48}$/D', $key)) {
            return null;
        }

        return LoginChallenge::query()->with('user')->where('key', $key)->where('purpose', LoginChallengePurpose::Login)->first();
    }

    private function recordFailedPassword(User $user): void
    {
        User::query()->whereKey($user->id)->increment('login_attempts');

        if ($user->refresh()->login_attempts >= self::MAX_PASSWORD_FAILURES) {
            $user->forceFill(['login_attempts' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)])->saveQuietly();
        }
    }

    private function replaceCode(LoginChallenge $challenge): string
    {
        $code = self::newCode();

        $challenge->forceFill([
            'code_hash' => self::hashCode($challenge->key, $code),
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ])->save();

        return $code;
    }

    private function sendCode(User $user, string $code): void
    {
        $this->mailer->send(
            $user->email,
            "Your sign-in code: {$code}",
            "Your sign-in verification code is:\n\n{$code}\n\nIt expires in 10 minutes. If you did not try to sign in, change your password and tell your System Admin.",
        );
    }

    private static function newCode(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    private static function hashCode(string $key, string $code): string
    {
        return hash_hmac('sha256', "{$key}:{$code}", (string) config('app.key'));
    }
}
