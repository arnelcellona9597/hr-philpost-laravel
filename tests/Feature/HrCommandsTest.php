<?php

use App\Enums\Role;
use App\Mail\SystemMessage;
use App\Models\User;
use App\Services\SignInService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

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
