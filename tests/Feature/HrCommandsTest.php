<?php

use App\Enums\Role;
use App\Mail\SystemMessage;
use App\Models\SmtpSetting;
use App\Models\User;
use App\Services\DirectMailer;
use App\Services\SignInService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;

beforeEach(fn () => config(['hr.admin_password' => 'fixture-password-1']));

it('creates the first System Admin', function () {
    $this->artisan('hr:create-admin', ['--name' => 'Ana', '--email' => 'ANA@example.test'])->assertSuccessful();

    $user = User::query()->firstOrFail();

    expect($user->email)->toBe('ana@example.test')
        ->and($user->role)->toBe(Role::SystemAdmin)
        ->and($user->isSystemAdmin())->toBeTrue()
        ->and(Hash::check('fixture-password-1', $user->password))->toBeTrue();
});

it('refuses a second admin without force', function () {
    User::factory()->systemAdmin()->create();

    $this->artisan('hr:create-admin', ['--name' => 'Ana', '--email' => 'ana@example.test'])->assertFailed();
    expect(User::query()->count())->toBe(1);

    $this->artisan('hr:create-admin', ['--name' => 'Ana', '--email' => 'ana@example.test', '--force' => true])->assertSuccessful();
    expect(User::query()->count())->toBe(2);
});

it('refuses weak passwords, bad addresses and duplicates', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->artisan('hr:create-admin', ['--name' => 'Ana', '--email' => 'not-an-email'])->assertFailed();
    $this->artisan('hr:create-admin', ['--name' => 'Ana', '--email' => 'taken@example.test'])->assertFailed();

    config(['hr.admin_password' => 'weak']);
    $this->artisan('hr:create-admin', ['--name' => 'Ana', '--email' => 'ana@example.test'])->assertFailed();

    expect(User::query()->where('role', Role::SystemAdmin)->count())->toBe(0);
});

it('never prints the password', function () {
    $this->artisan('hr:create-admin', ['--name' => 'Ana', '--email' => 'ana@example.test'])
        ->doesntExpectOutputToContain('fixture-password-1')
        ->assertSuccessful();
});

it('needs a password source when not interactive', function () {
    config(['hr.admin_password' => null]);

    $this->artisan('hr:create-admin', ['--name' => 'Ana', '--email' => 'ana@example.test', '--no-interaction' => true])->assertFailed();
});

it('asks for the password interactively', function () {
    config(['hr.admin_password' => null]);

    $this->artisan('hr:create-admin', ['--name' => 'Ana', '--email' => 'ana@example.test'])
        ->expectsQuestion('Password (min. 10 characters, letters and numbers)', 'typed-password-1')
        ->expectsQuestion('Repeat password', 'typed-password-1')
        ->assertSuccessful();

    expect(Hash::check('typed-password-1', User::query()->first()->password))->toBeTrue();
});

it('resets a password, unlocks the account and ends its sessions', function () {
    $user = User::factory()->create(['email' => 'ana@example.test']);
    $user->forceFill(['locked_until' => now()->addMinutes(10)])->save();
    DB::table('sessions')->insert(['id' => 'old', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

    $this->artisan('hr:create-admin', ['--reset-password' => 'ANA@example.test'])->assertSuccessful();

    expect(Hash::check('fixture-password-1', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->isLocked())->toBeFalse()
        ->and(DB::table('sessions')->count())->toBe(0);
});

it('fails a reset for an unknown account', function () {
    $this->artisan('hr:create-admin', ['--reset-password' => 'nobody@example.test'])->assertFailed();
});

it('issues a break-glass code for a sign-in in progress', function () {
    Mail::fake();
    User::factory()->create(['email' => 'ana@example.test', 'password' => 'correct-horse-1']);
    $service = app(SignInService::class);
    $key = $service->start('ana@example.test', 'correct-horse-1');

    $this->artisan('hr:issue-login-code', ['email' => 'ana@example.test'])->assertSuccessful();

    $code = $service->issueCodeFor(User::query()->first());

    expect($service->verify($key, $code))->toHaveKey('user');
    Mail::assertSent(SystemMessage::class, 1);
});

it('refuses a code when no sign-in is in progress', function () {
    User::factory()->create(['email' => 'ana@example.test']);

    $this->artisan('hr:issue-login-code', ['email' => 'ana@example.test'])->assertFailed();
    $this->artisan('hr:issue-login-code', ['email' => 'nobody@example.test'])->assertFailed();
});

it('reports a failed backup and never puts the database password on the command line', function () {
    config(['database.connections.mysql.password' => 'database-secret']);
    Process::fake(fn () => Process::result(errorOutput: 'mysqldump: not found', exitCode: 127));

    $this->artisan('hr:backup')->expectsOutputToContain('mysqldump: not found')->assertFailed();

    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'mysqldump') && ! str_contains(implode(' ', (array) $process->command), 'database-secret'));
});

function smtpConfig(array $overrides = []): void
{
    config(['hr.smtp' => [
        'host' => 'smtp.example.test',
        'port' => '587',
        'security' => 'starttls',
        'username' => 'info@example.test',
        'password' => 'fixture-mail-password-1',
        'from_address' => null,
        'from_name' => 'HR Office',
        ...$overrides,
    ]]);
}

it('saves the mail server from the environment, checks it and sends a test', function () {
    smtpConfig();
    $mailer = Mockery::mock(DirectMailer::class);
    $mailer->shouldReceive('verifyConnection')->once()->andReturnNull();
    $mailer->shouldReceive('deliver')->once()->with('info@example.test', Mockery::type('string'), Mockery::type('string'));
    app()->instance(DirectMailer::class, $mailer);

    $this->artisan('hr:configure-smtp', ['--test' => 'info@example.test'])
        ->expectsOutputToContain('Connected and signed in')
        ->doesntExpectOutputToContain('fixture-mail-password-1')
        ->assertSuccessful();

    $settings = SmtpSetting::current();

    expect($settings->host)->toBe('smtp.example.test')
        ->and($settings->port)->toBe(587)
        ->and($settings->from_address)->toBe('info@example.test')
        ->and($settings->password)->toBe('fixture-mail-password-1')
        ->and(DB::table('smtp_settings')->value('password'))->not->toContain('fixture-mail-password-1');
});

it('reports a mail server it could not sign in to', function () {
    smtpConfig();
    $mailer = Mockery::mock(DirectMailer::class);
    $mailer->shouldReceive('verifyConnection')->once()->andReturn('Authentication failed');
    app()->instance(DirectMailer::class, $mailer);

    $this->artisan('hr:configure-smtp')->expectsOutputToContain('Connecting failed: Authentication failed')->assertFailed();
});

it('does not claim success when real email is switched off', function () {
    smtpConfig();

    $this->artisan('hr:configure-smtp')->expectsOutputToContain('MAIL_MAILER is not set to smtp')->assertFailed();
});

it('refuses mail server values that cannot work', function (array $overrides, array $arguments, string $message) {
    smtpConfig($overrides);

    $this->artisan('hr:configure-smtp', $arguments)->expectsOutputToContain($message)->assertFailed();

    expect(DB::table('smtp_settings')->count())->toBe(0);
})->with([
    'host' => [['host' => ''], [], 'Enter a valid host name'],
    'port' => [['port' => 'x'], [], 'Enter a valid port'],
    'security' => [['security' => 'tls'], [], 'Choose the connection security'],
    'password' => [['password' => ''], [], 'HR_SMTP_PASSWORD is empty'],
    'test address' => [[], ['--test' => 'nope'], 'The --test address is not valid'],
]);
