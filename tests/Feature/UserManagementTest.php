<?php

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\UserFacingException;
use App\Mail\SystemMessage;
use App\Models\User;
use App\Services\DirectMailer;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

function admin(): User
{
    return User::factory()->systemAdmin()->create();
}

it('sends staff away from account management', function (string $method, string $uri) {
    $target = User::factory()->create();

    signIn(User::factory()->create())->call($method, str_replace('{id}', $target->id, $uri))->assertRedirect('/?denied=1');
})->with([
    ['GET', '/users'],
    ['POST', '/users'],
    ['PUT', '/users/{id}'],
    ['POST', '/users/{id}/unlock'],
    ['POST', '/users/{id}/link'],
    ['DELETE', '/users/{id}'],
]);

it('lists accounts for System Admins', function () {
    User::factory()->create(['name' => 'Ben Reyes']);

    signIn(admin())->get('/users')->assertSuccessful()->assertSee('Ben Reyes')->assertSee('You');
});

it('flags accounts that have not finished their invitation', function () {
    User::factory()->unverified()->create();

    signIn(admin())->get('/users')->assertSee('Invitation pending')->assertSee('Resend invitation');
});

it('invites a new account', function () {
    signIn(admin())->post('/users', ['name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'hr-staff'])->assertRedirect('/users')->assertSessionHas('flash.type', 'success');

    $user = User::query()->where('email', 'ana@example.test')->firstOrFail();

    expect($user->role)->toBe(Role::HrStaff)->and($user->isApproved())->toBeTrue();
    Mail::assertSent(SystemMessage::class, 1);
});

it('shows the link to the admin when email is down', function () {
    $this->mock(DirectMailer::class, fn ($mock) => $mock->shouldReceive('send')->andReturnFalse()->shouldReceive('isConfigured')->andReturnTrue());

    signIn(admin())->post('/users', ['name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'hr-staff'])->assertSessionHas('flash.type', 'info');

    expect(session('flash.message'))->toContain('/set-password?token=');
});

it('refuses duplicate addresses and unknown roles', function () {
    User::factory()->create(['email' => 'ana@example.test']);

    signIn(admin())->from('/users')->post('/users', ['name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'hr-staff'])
        ->assertSessionHasErrors(['email' => 'That email address already has an account.']);

    $this->post('/users', ['name' => 'B', 'email' => 'b@example.test', 'role' => 'owner'])->assertSessionHasErrors('role');
});

it('changes a role and status', function () {
    $user = User::factory()->create();

    signIn(admin())->put("/users/{$user->id}", ['role' => 'system-admin', 'status' => 'disabled'])->assertRedirect('/users');

    expect($user->fresh()->role)->toBe(Role::SystemAdmin)
        ->and($user->fresh()->status)->toBe(UserStatus::Disabled);
});

it('only allows approved or disabled from the form', function () {
    signIn(admin())->put('/users/'.User::factory()->create()->id, ['role' => 'hr-staff', 'status' => 'pending'])->assertSessionHasErrors('status');
});

it('stamps the approval date', function () {
    $user = User::factory()->pending()->create();

    signIn(admin())->put("/users/{$user->id}", ['role' => 'hr-staff', 'status' => 'approved']);

    expect($user->fresh()->approved_at)->not->toBeNull();
});

it('keeps the last System Admin in place', function (string $role, string $status) {
    $admin = admin();

    signIn($admin)->put("/users/{$admin->id}", ['role' => $role, 'status' => $status])->assertSessionHas('flash.message', 'At least one approved System Admin must remain.');

    expect($admin->fresh()->isSystemAdmin())->toBeTrue();
})->with([
    ['hr-staff', 'approved'],
    ['system-admin', 'disabled'],
]);

it('allows demotion while another System Admin remains', function () {
    $first = admin();
    admin();

    signIn($first)->put("/users/{$first->id}", ['role' => 'hr-staff', 'status' => 'approved']);

    expect($first->fresh()->role)->toBe(Role::HrStaff);
});

it('does not count a disabled admin as remaining', function () {
    $admin = admin();
    User::factory()->systemAdmin()->disabled()->create();

    signIn($admin)->put("/users/{$admin->id}", ['role' => 'hr-staff', 'status' => 'approved'])->assertSessionHas('flash.type', 'error');
});

it('unlocks an account', function () {
    $user = User::factory()->create();
    $user->forceFill(['locked_until' => now()->addMinutes(10)])->save();

    signIn(admin())->get('/users')->assertSee('Unlock');
    $this->post("/users/{$user->id}/unlock")->assertRedirect('/users');

    expect($user->fresh()->isLocked())->toBeFalse();
});

it('sends a reset link or a new invitation', function (string $kind, string $subject) {
    $user = User::factory()->create();

    signIn(admin())->post("/users/{$user->id}/link", ['kind' => $kind])->assertRedirect('/users');

    Mail::assertSent(SystemMessage::class, fn (SystemMessage $mail) => $mail->subjectLine === $subject && $mail->hasTo($user->email));
})->with([
    ['reset', 'Reset your HR System password'],
    ['invite', 'Your HR System account — set your password'],
]);

it('deletes other accounts but never your own', function () {
    $admin = admin();
    $user = User::factory()->create();

    signIn($admin)->delete("/users/{$user->id}")->assertRedirect('/users');
    expect($user->fresh())->toBeNull();

    $this->delete("/users/{$admin->id}")->assertForbidden();
    expect($admin->fresh())->not->toBeNull();
});

it('needs a confirmed address again after an email change', function () {
    $user = User::factory()->create();

    $user->update(['email' => 'new@example.test']);
    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('protects the last admin at the model level too', function () {
    expect(fn () => admin()->delete())->toThrow(UserFacingException::class);
});
