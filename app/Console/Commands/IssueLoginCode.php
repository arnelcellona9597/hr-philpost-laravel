<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SignInService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('hr:issue-login-code {email : Account that has started signing in}')]
#[Description('Print a sign-in code for an account when email is down')]
class IssueLoginCode extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SignInService $signIn): int
    {
        $user = User::query()->where('email', Str::lower($this->argument('email')))->first();
        $code = $user ? $signIn->issueCodeFor($user) : null;

        if ($code === null) {
            $this->error('No sign-in in progress for that account. Enter the password on the sign-in page first.');

            return self::FAILURE;
        }

        $this->line("Sign-in code: {$code} (valid for 10 minutes, replaces any emailed code)");

        return self::SUCCESS;
    }
}
