<?php

use App\Enums\LoginChallengePurpose;
use App\Mail\SystemMessage;
use App\Models\LoginChallenge;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(fn () => Mail::fake());

function account(array $attributes = []): User
{
    return User::factory()->create(['email' => 'ana@example.test', 'password' => 'correct-horse-1', ...$attributes]);
}

function submitPassword(string $password = 'correct-horse-1', string $next = ''): mixed
{
    return test()->post('/login', ['email' => 'ana@example.test', 'password' => $password, 'next' => $next]);
}

function emailedCode(): string
{
    $code = null;

    Mail::assertSent(SystemMessage::class, function (SystemMessage $mail) use (&$code) {
        $code = Str::afterLast($mail->subjectLine, ' ');

        return true;
    });

    return $code;
}

function wrongCode(string $right): string
{
    return $right === '000000' ? '000001' : '000000';
}

it('asks for a code after the right password and gives no session yet', function () {
    account();

    submitPassword()->assertRedirect('/login/verify');

    $this->assertGuest();
    Mail::assertSent(SystemMessage::class, fn (SystemMessage $mail) => $mail->hasTo('ana@example.test') && Str::contains($mail->bodyText, 'expires in 10 minutes'));
});

it('signs in with the emailed code and honours a safe next', function () {
    $user = account();
    $this->freezeTime();

    submitPassword(next: '/profile');

    $this->post('/login/verify', ['code' => emailedCode()])->assertRedirect('/profile');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and(LoginChallenge::query()->count())->toBe(0)
        ->and(session('auth_expires_at'))->toBe(now()->addHours(12)->getTimestamp());
});

it('ignores an unsafe next', function () {
    account();

    submitPassword(next: '//evil.example');

    $this->post('/login/verify', ['code' => emailedCode()])->assertRedirect('/');
});

it('accepts mixed-case addresses', function () {
    account();

    $this->post('/login', ['email' => ' ANA@Example.test ', 'password' => 'correct-horse-1'])->assertRedirect('/login/verify');
});

it('stores only a hash of the code', function () {
    account();
    submitPassword();

    expect(LoginChallenge::query()->first()->code_hash)->not->toContain(emailedCode())->toHaveLength(64);
});

it('gives the same answer for a wrong password and an unknown address', function () {
    account();

    submitPassword('wrong-password-1')->assertSessionHasErrors(['email' => 'Incorrect email or password.']);
    $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'x'])->assertSessionHasErrors(['email' => 'Incorrect email or password.']);

    Mail::assertNothingSent();
});

it('shows the error on the page after the redirect', function () {
    account();

    $this->from('/login')->followingRedirects()->post('/login', ['email' => 'ana@example.test', 'password' => 'wrong-password-1'])
        ->assertSee('Incorrect email or password.')
        ->assertSee('value="ana@example.test"', false)
        ->assertDontSee('wrong-password-1');
});

it('shows a wrong code on the code page', function () {
    account();
    submitPassword();

    $this->from('/login/verify')->followingRedirects()->post('/login/verify', ['code' => wrongCode(emailedCode())])
        ->assertSee('That code is not correct. 4 attempts left.');
});

it('refuses accounts that are not approved, but only after the right password', function (string $state, string $message) {
    User::factory()->{$state}()->create(['email' => 'ana@example.test', 'password' => 'correct-horse-1']);

    submitPassword()->assertSessionHasErrors(['email' => $message]);
    submitPassword('wrong-password-1')->assertSessionHasErrors(['email' => 'Incorrect email or password.']);

    Mail::assertNothingSent();
})->with([
    ['pending', 'Your account is waiting for approval by a System Admin.'],
    ['disabled', 'Your account has been disabled. Contact a System Admin.'],
]);

it('locks an account for 15 minutes after 5 failed passwords', function () {
    $user = account();

    foreach (range(1, 5) as $attempt) {
        submitPassword('wrong-password-1');
    }

    submitPassword()->assertSessionHasErrors(['email' => 'Too many failed attempts. This account is locked for 15 minutes.']);
    Mail::assertNothingSent();

    $this->travel(16)->minutes();

    submitPassword()->assertRedirect('/login/verify');
    expect($user->fresh()->locked_until)->toBeNull();
});

it('clears the failure count after a right password', function () {
    $user = account();

    foreach (range(1, 4) as $attempt) {
        submitPassword('wrong-password-1');
    }

    submitPassword();

    expect($user->fresh()->login_attempts)->toBe(0);
});

it('counts down wrong codes and ends the challenge at the limit', function () {
    account();
    submitPassword();
    $wrong = wrongCode(emailedCode());

    foreach ([4, 3, 2] as $left) {
        $this->post('/login/verify', ['code' => $wrong])->assertSessionHasErrors(['code' => "That code is not correct. {$left} attempts left."]);
    }

    $this->post('/login/verify', ['code' => $wrong])->assertSessionHasErrors(['code' => 'That code is not correct. 1 attempt left.']);

    $this->post('/login/verify', ['code' => $wrong])->assertRedirect('/login?notice=restart');

    $this->assertGuest();
    expect(LoginChallenge::query()->count())->toBe(0);
});

it('refuses the right code once the attempts are used up', function () {
    account();
    submitPassword();
    LoginChallenge::query()->first()->forceFill(['attempts' => 5])->save();

    $this->post('/login/verify', ['code' => emailedCode()])->assertRedirect('/login?notice=restart');

    $this->assertGuest();
});

it('expires codes after 10 minutes', function () {
    account();
    submitPassword();

    $this->travel(11)->minutes();

    $this->post('/login/verify', ['code' => emailedCode()])->assertRedirect('/login?notice=restart');
    $this->assertGuest();
});

it('uses a code only once', function () {
    account();
    submitPassword();
    $code = emailedCode();
    $key = session('login.challenge');

    $this->post('/login/verify', ['code' => $code]);
    auth()->logout();

    $this->withSession(['login.challenge' => $key])->post('/login/verify', ['code' => $code])->assertRedirect('/login?notice=restart');
    $this->assertGuest();
});

it('refuses a code when the account was disabled meanwhile', function () {
    $user = account();
    submitPassword();
    $user->forceFill(['status' => 'disabled'])->save();

    $this->post('/login/verify', ['code' => emailedCode()])->assertRedirect('/login?notice=restart');

    $this->assertGuest();
});

it('waits 60 seconds between codes', function () {
    account();
    submitPassword();

    $this->post('/login/verify/resend')->assertSessionHasErrors('code');
    Mail::assertSentCount(1);

    $this->travel(61)->seconds();

    $this->post('/login/verify/resend')->assertSessionHas('status', 'A new code was sent.');
    Mail::assertSentCount(2);
});

it('sends at most 5 codes and keeps the attempt count', function () {
    account();
    submitPassword();
    LoginChallenge::query()->first()->forceFill(['attempts' => 3])->save();

    foreach (range(1, 4) as $send) {
        $this->travel(61)->seconds();
        $this->post('/login/verify/resend');
    }

    $this->travel(61)->seconds();
    $this->post('/login/verify/resend')->assertSessionHasErrors(['code' => 'Too many codes sent. Please sign in again later.']);

    Mail::assertSentCount(5);
    expect(LoginChallenge::query()->first()->attempts)->toBe(3);
});

it('makes a resent code replace the old one and extends the expiry', function () {
    account();
    submitPassword();
    $before = LoginChallenge::query()->first();

    $this->travel(5)->minutes();
    $this->post('/login/verify/resend');

    $after = LoginChallenge::query()->first();

    expect($after->code_hash)->not->toBe($before->code_hash)
        ->and($after->expires_at->gt($before->expires_at))->toBeTrue();
});

it('keeps one open challenge per user', function () {
    account();
    submitPassword();
    submitPassword();

    expect(LoginChallenge::query()->where('purpose', LoginChallengePurpose::Login)->count())->toBe(1);
});

it('lets the person use another account', function () {
    account();
    submitPassword();

    $this->post('/login/verify/cancel')->assertRedirect('/login');

    expect(LoginChallenge::query()->count())->toBe(0);
    $this->get('/login/verify')->assertRedirect('/login');
});

it('masks the address on the code page', function () {
    account();
    submitPassword();

    $this->get('/login/verify')->assertSuccessful()->assertSee('an•@example.test');
});

it('sends signed-in people away from the sign-in page', function () {
    signIn(User::factory()->create())->get('/login')->assertRedirect('/');
});

it('explains why someone was sent back to sign in', function (string $notice, string $text) {
    $this->get("/login?notice={$notice}")->assertSee($text);
})->with([
    ['expired', 'sessions last 12 hours'],
    ['restart', 'The verification code expired'],
    ['password-set', 'Password saved.'],
    ['no-signup', 'Accounts are created by a System Admin.'],
    ['password', 'Password changed.'],
]);

it('sends guests to sign in and remembers where they were going', function () {
    $this->get('/profile?tab=1')->assertRedirect('/login?next=%2Fprofile%3Ftab%3D1');
});

it('answers guest form posts with a plain 401', function () {
    $this->put('/profile/name', ['name' => 'X'])->assertUnauthorized();
});

it('ends a session 12 hours after sign-in, however active', function () {
    $user = User::factory()->create();
    $this->freezeTime();

    signIn($user)->get('/profile')->assertSuccessful();

    $this->travel(12)->hours();

    $this->get('/profile')->assertRedirect('/login?notice=expired');
    $this->assertGuest();
});

it('refuses a session with no expiry', function () {
    $this->actingAs(User::factory()->create())->get('/profile')->assertRedirect('/login?notice=expired');
});

it('ends the session as soon as an account is disabled', function () {
    $user = User::factory()->create();

    signIn($user)->get('/profile')->assertSuccessful();

    $user->forceFill(['status' => 'disabled'])->save();

    $this->get('/profile')->assertRedirect('/login?next=%2Fprofile');
    $this->assertGuest();
});

it('logs out with a post', function () {
    signIn(User::factory()->create())->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
});

it('has no public sign-up', function () {
    $this->get('/register')->assertRedirect('/login?notice=no-signup');
    $this->post('/register', ['email' => 'x@example.test'])->assertRedirect('/login?notice=no-signup');

    expect(User::query()->count())->toBe(0);
});

it('rate limits sign-in posts', function () {
    foreach (range(1, 20) as $attempt) {
        $this->post('/login', ['email' => 'a@example.test', 'password' => 'x'])->assertRedirect();
    }

    $this->post('/login', ['email' => 'a@example.test', 'password' => 'x'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
});
