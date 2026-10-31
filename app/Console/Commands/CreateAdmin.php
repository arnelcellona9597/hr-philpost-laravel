<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Rules\HrPassword;
use App\Services\AccountService;
use App\Support\GovId;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

#[Signature('hr:create-admin {--name= : Full name} {--email= : Email address} {--force : Create another System Admin} {--reset-password= : Set a new password for this existing account}')]
#[Description('Create a System Admin, or reset the password of any account')]
class CreateAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AccountService $accounts): int
    {
        try {
            $resetEmail = $this->option('reset-password');

            $resetEmail !== null ? $this->resetPassword($accounts, Str::lower($resetEmail)) : $this->createAdmin();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resetPassword(AccountService $accounts, string $email): void
    {
        $user = User::query()->where('email', $email)->first() ?? throw new RuntimeException("No account with email {$email}.");

        $user->forceFill(['password' => $this->newPassword()])->save();
        $accounts->unlock($user);
        $accounts->endAllSessions($user);

        $this->info("Password updated for {$email}. Sign in at the website (an email code is still required).");
    }

    private function createAdmin(): void
    {
        if (User::query()->where('role', Role::SystemAdmin)->exists() && ! $this->option('force')) {
            throw new RuntimeException('A System Admin already exists. Add more accounts from the HR Accounts page, or run again with --force.');
        }

        $name = trim((string) ($this->option('name') ?? $this->ask('Full name')));
        $email = Str::lower(trim((string) ($this->option('email') ?? $this->ask('Email'))));

        if ($name === '') {
            throw new RuntimeException('A name is required.');
        }

        if ($email === '' || GovId::validateEmail($email) !== null) {
            throw new RuntimeException('A valid email address is required.');
        }

        if (User::query()->where('email', $email)->exists()) {
            throw new RuntimeException("An account with email {$email} already exists.");
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $this->newPassword()]);
        $user->forceFill(['role' => Role::SystemAdmin, 'status' => UserStatus::Approved])->save();

        $this->info("System Admin {$email} created. Sign in at the website; the first sign-in confirms the email with a code.");
    }

    private function newPassword(): string
    {
        $password = config('hr.admin_password');

        if (filled($password)) {
            if (HrPassword::problem($password) !== null) {
                throw new RuntimeException('HR_ADMIN_PASSWORD is too weak.');
            }

            return $password;
        }

        if (! $this->input->isInteractive()) {
            throw new RuntimeException('Set HR_ADMIN_PASSWORD or run this command interactively.');
        }

        $password = (string) $this->secret('Password (min. 10 characters, letters and numbers)');

        if (($problem = HrPassword::problem($password)) !== null) {
            throw new RuntimeException($problem);
        }

        if ($password !== $this->secret('Repeat password')) {
            throw new RuntimeException('Passwords do not match.');
        }

        return $password;
    }
}
