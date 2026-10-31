<?php

use App\Enums\LoginChallengePurpose;
use App\Enums\Role;
use App\Mail\SystemMessage;
use App\Models\LoginChallenge;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(fn () => Mail::fake());

function issueLink(User $user, LoginChallengePurpose $kind = LoginChallengePurpose::Reset): string
{
    return Str::after(app(AccountService::class)->issueLink($user, $kind)['link'], 'token=');
}

function savePassword(string $token, string $password = 'new-password-1', ?string $confirmation = null): mixed
{
    return test()->from("/set-password?token={$token}")->post('/set-password', [
        'token' => $token,
        'password' => $password,
        'password_confirmation' => $confirmation ?? $password,
    ]);
}

it('stores only a hash of the link token', function () {
    $token = issueLink(User::factory()->create());

    expect($token)->toHaveLength(64)
        ->and(LoginChallenge::query()->first()->key)->toBe(hash('sha256', $token));
});

it('keeps code and link challenges apart', function () {
    issueLink(User::factory()->create(), LoginChallengePurpose::Invite);

    $challenge = LoginChallenge::query()->first();

    expect($challenge->purpose)->toBe(LoginChallengePurpose::Invite)
        ->and($challenge->code_hash)->toBeNull();
});

it('creates invited accounts approved, with no usable password', function () {
    $result = app(AccountService::class)->invite(' Ana ', ' ANA@Example.test ', Role::HrStaff);

    expect($result['user']->name)->toBe('Ana')
        ->and($result['user']->email)->toBe('ana@example.test')
        ->and($result['user']->isApproved())->toBeTrue()
        ->and($result['user']->email_verified_at)->toBeNull();

    Mail::assertSent(SystemMessage::class, fn (SystemMessage $mail) => Str::contains($mail->bodyText, $result['link']));
});

it('sets a password once and shows nothing for a used link', function () {
    $user = User::factory()->unverified()->create();
    $token = issueLink($user, LoginChallengePurpose::Invite);

    $this->get("/set-password?token={$token}")->assertSee('Welcome — choose your password');
    savePassword($token)->assertRedirect('/login?notice=password-set');

    expect(Hash::check('new-password-1', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->email_verified_at)->not->toBeNull();

    $this->get("/set-password?token={$token}")->assertSee('invalid, was already used');
    savePassword($token, 'another-password-2')->assertSessionHasErrors('password');
    expect(Hash::check('new-password-1', $user->fresh()->password))->toBeTrue();
});

it('expires invites after 24 hours and resets after 1 hour', function (LoginChallengePurpose $kind, int $hours) {
    $token = issueLink(User::factory()->create(), $kind);

    $this->travel($hours)->hours();
    $this->travel(1)->minutes();

    $this->get("/set-password?token={$token}")->assertSee('invalid, was already used');
    expect(LoginChallenge::query()->count())->toBe(0);
})->with([
    [LoginChallengePurpose::Invite, 24],
    [LoginChallengePurpose::Reset, 1],
]);

it('replaces the previous link', function () {
    $user = User::factory()->create();
    $first = issueLink($user);
    issueLink($user);

    $this->get("/set-password?token={$first}")->assertSee('invalid, was already used');
    expect(LoginChallenge::query()->count())->toBe(1);
});

it('refuses links of disabled accounts', function () {
    $user = User::factory()->create();
    $token = issueLink($user);
    $user->forceFill(['status' => 'disabled'])->save();

    $this->get("/set-password?token={$token}")->assertSee('invalid, was already used');
    savePassword($token)->assertSessionHasErrors('password');

    expect(Hash::check('new-password-1', $user->fresh()->password))->toBeFalse();
});

it('unlocks the account and signs it out everywhere', function () {
    $user = User::factory()->create();
    $user->forceFill(['locked_until' => now()->addMinutes(10), 'login_attempts' => 3])->save();
    DB::table('sessions')->insert(['id' => 'stolen', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

    savePassword(issueLink($user));

    expect($user->fresh()->isLocked())->toBeFalse()
        ->and($user->fresh()->login_attempts)->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('checks password rules before spending the link', function (string $password, ?string $confirmation, string $message) {
    $token = issueLink(User::factory()->create());

    savePassword($token, $password, $confirmation)->assertSessionHasErrors(['password' => $message]);

    expect(LoginChallenge::query()->count())->toBe(1);
})->with([
    ['short1', null, 'Password must be at least 10 characters.'],
    ['onlyletterslong', null, 'Password must contain both letters and numbers.'],
    ['12345678901', null, 'Password must contain both letters and numbers.'],
    ['new-password-1', 'different-1', 'Passwords do not match.'],
]);

it('rejects malformed tokens', function () {
    $this->get('/set-password?token=abc')->assertSee('invalid, was already used');
});

it('forwards old reset links', function () {
    $this->get('/reset-password?token=abc')->assertRedirect('/set-password?token=abc');
});

it('answers forgot-password the same whether or not the address exists', function (string $email) {
    User::factory()->create(['email' => 'ana@example.test']);

    $this->withoutDefer()->from('/forgot-password')->post('/forgot-password', ['email' => $email])->assertRedirect('/forgot-password')->assertSessionHas('sent', true);
    $this->get('/forgot-password')->assertSee('If that email belongs to an account');
})->with(['ana@example.test', 'nobody@example.test']);

it('emails a reset link to approved accounts only', function () {
    User::factory()->create(['email' => 'ana@example.test']);
    User::factory()->disabled()->create(['email' => 'off@example.test']);

    $this->withoutDefer()->post('/forgot-password', ['email' => 'off@example.test']);
    Mail::assertNothingSent();

    $this->withoutDefer()->post('/forgot-password', ['email' => 'ANA@example.test']);
    Mail::assertSent(SystemMessage::class, fn (SystemMessage $mail) => $mail->subjectLine === 'Reset your HR System password');
});

it('keeps links out of the message outbox', function () {
    issueLink(User::factory()->create());

    expect(DB::table('messages')->count())->toBe(0);
});
