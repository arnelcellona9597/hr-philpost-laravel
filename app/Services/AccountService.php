<?php

namespace App\Services;

use App\Enums\LoginChallengePurpose;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\UserFacingException;
use App\Models\LoginChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HR accounts are created by System Admins. New users get a link to choose their own password, and
 * "forgot password" uses the same single-use links. Only the SHA-256 of a link token is stored.
 */
class AccountService
{
    private const INVITE_TTL_HOURS = 24;

    private const RESET_TTL_HOURS = 1;

    public function __construct(protected DirectMailer $mailer) {}

    /**
     * Add an account that has no usable password until the invite link is used.
     *
     * @return array{user: User, delivered: bool, link: string}
     */
    public function invite(string $name, string $email, Role $role): array
    {
        $user = new User(['name' => trim($name), 'email' => Str::lower(trim($email)), 'password' => Str::password(40)]);
        $user->forceFill(['role' => $role, 'status' => UserStatus::Approved])->save();

        return ['user' => $user, ...$this->issueLink($user, LoginChallengePurpose::Invite)];
    }

    /**
     * Create or replace a set-password link and email it.
     *
     * @return array{delivered: bool, link: string}
     */
    public function issueLink(User $user, LoginChallengePurpose $kind): array
    {
        $user->challenges()->whereIn('purpose', [LoginChallengePurpose::Invite, LoginChallengePurpose::Reset])->delete();

        $isInvite = $kind === LoginChallengePurpose::Invite;
        $token = bin2hex(random_bytes(32));

        $user->challenges()->create([
            'key' => hash('sha256', $token),
            'purpose' => $kind,
            'expires_at' => now()->addHours($isInvite ? self::INVITE_TTL_HOURS : self::RESET_TTL_HOURS),
            'last_sent_at' => now(),
        ]);

        $link = route('password.set', ['token' => $token]);

        $delivered = $this->mailer->send(
            $user->email,
            $isInvite ? 'Your HR System account — set your password' : 'Reset your HR System password',
            $isInvite
                ? "Hi {$user->name},\n\nAn HR System account was created for you. Choose your password here (link valid for 24 hours, single use):\n\n{$link}\n\nAfter that, sign in with your email and password. Each sign-in also asks for a code we email to you."
                : "Hi {$user->name},\n\nSomeone asked to reset the password of your HR System account. Choose a new password here (link valid for 1 hour, single use):\n\n{$link}\n\nIf this wasn't you, ignore this email — your password stays the same.",
        );

        return ['delivered' => $delivered, 'link' => $link];
    }

    /**
     * "Forgot password" looks the same from outside whether or not the address exists.
     */
    public function requestReset(string $email): void
    {
        $user = User::query()->where('email', Str::lower(trim($email)))->where('status', UserStatus::Approved)->first();

        if ($user !== null) {
            $this->issueLink($user, LoginChallengePurpose::Reset);
        }
    }

    /**
     * @return array{kind: LoginChallengePurpose, name: string, maskedEmail: string}|null
     */
    public function linkInfo(?string $token): ?array
    {
        $challenge = $this->findLink($token);

        if ($challenge === null || ! $challenge->user->isApproved()) {
            return null;
        }

        return [
            'kind' => $challenge->purpose,
            'name' => $challenge->user->name,
            'maskedEmail' => SignInService::maskEmail($challenge->user->email),
        ];
    }

    /**
     * Set the password from a link. The link works once and the user is signed out everywhere.
     *
     * @throws UserFacingException when the link is no longer usable
     */
    public function setPasswordWithLink(?string $token, string $password): void
    {
        $challenge = $this->findLink($token);

        if ($challenge === null || ! $challenge->user->isApproved()) {
            throw new UserFacingException('This link is invalid or has expired. Ask your System Admin for a new one.');
        }

        // Claim the link first so it can only be used once, even with parallel requests.
        if (LoginChallenge::query()->whereKey($challenge->id)->delete() === 0) {
            throw new UserFacingException('This link was already used.');
        }

        $challenge->user->forceFill([
            'password' => $password,
            'email_verified_at' => $challenge->user->email_verified_at ?? now(),
            'login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $this->endAllSessions($challenge->user);
    }

    public function unlock(User $user): void
    {
        $user->forceFill(['login_attempts' => 0, 'locked_until' => null])->save();
    }

    /**
     * Change a role or status. The rows are locked so two admins can't demote each other at once.
     */
    public function changeAccess(User $user, Role $role, UserStatus $status): void
    {
        DB::transaction(fn () => $user->forceFill(['role' => $role, 'status' => $status])->save());
    }

    public function delete(User $user): void
    {
        DB::transaction(fn () => $user->delete());
    }

    /**
     * End every browser session of the account, so a stolen one stops working.
     */
    public function endAllSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    private function findLink(?string $token): ?LoginChallenge
    {
        if ($token === null || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }

        $challenge = LoginChallenge::query()
            ->with('user')
            ->where('key', hash('sha256', $token))
            ->whereIn('purpose', [LoginChallengePurpose::Invite, LoginChallengePurpose::Reset])
            ->first();

        if ($challenge?->expires_at->isPast()) {
            $challenge->delete();

            return null;
        }

        return $challenge;
    }
}
