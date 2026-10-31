<?php

use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

const PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

function pngUpload(string $name = 'me photo.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode(PIXEL_PNG));
}

it('shows the account', function () {
    signIn(User::factory()->create(['name' => 'Ana']))->get('/profile')->assertSuccessful()->assertSee('Ana')->assertSee('HR Staff');
});

it('changes the name but cannot touch role, status or email', function () {
    $user = User::factory()->create();

    signIn($user)->put('/profile/name', ['name' => ' Ana R ', 'role' => 'system-admin', 'status' => 'disabled', 'email' => 'x@example.test'])->assertRedirect('/profile');

    $fresh = $user->fresh();

    expect($fresh->name)->toBe('Ana R')
        ->and($fresh->role)->toBe($user->role)
        ->and($fresh->status)->toBe($user->status)
        ->and($fresh->email)->toBe($user->email);
});

it('stores a picture privately using the detected type', function () {
    $user = User::factory()->create();

    signIn($user)->post('/profile/avatar', ['avatar' => pngUpload('../me photo.exe')])->assertRedirect('/profile');

    $media = $user->fresh()->avatar;

    expect($media->mime_type)->toBe('image/png')
        ->and($media->filename)->toBe('me_photo.exe')
        ->and($media->path)->toEndWith('.png')
        ->and($media->is_public)->toBeFalse();
    Storage::disk('local')->assertExists($media->path);
});

it('refuses non-images whatever they are called', function (UploadedFile $file) {
    $user = User::factory()->create();

    signIn($user)->from('/profile')->post('/profile/avatar', ['avatar' => $file])->assertSessionHasErrors('avatar');

    expect($user->fresh()->avatar_id)->toBeNull()
        ->and(Media::query()->count())->toBe(0);
})->with([
    'script named png' => fn () => UploadedFile::fake()->createWithContent('photo.png', '<?php echo 1;'),
    'svg' => fn () => UploadedFile::fake()->createWithContent('photo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'pdf' => fn () => UploadedFile::fake()->createWithContent('photo.png', "%PDF-1.4\n%âãÏÓ\n"),
]);

it('refuses pictures over 2 MB', function () {
    $file = UploadedFile::fake()->createWithContent('big.png', base64_decode(PIXEL_PNG).str_repeat('0', 2 * 1024 * 1024));

    signIn(User::factory()->create())->from('/profile')->post('/profile/avatar', ['avatar' => $file])
        ->assertSessionHasErrors(['avatar' => 'The file must be 2 MB or smaller.']);
});

it('deletes the old picture when replacing it', function () {
    $user = User::factory()->create();

    signIn($user)->post('/profile/avatar', ['avatar' => pngUpload()]);
    $first = $user->fresh()->avatar;

    $this->post('/profile/avatar', ['avatar' => pngUpload()]);

    expect(Media::query()->find($first->id))->toBeNull();
    Storage::disk('local')->assertMissing($first->path);
});

it('removes the picture', function () {
    $user = User::factory()->create();

    signIn($user)->post('/profile/avatar', ['avatar' => pngUpload()]);
    $media = $user->fresh()->avatar;

    $this->delete('/profile/avatar')->assertRedirect('/profile');

    expect($user->fresh()->avatar_id)->toBeNull();
    Storage::disk('local')->assertMissing($media->path);
});

it('changes the password with proof and signs out everywhere', function () {
    $user = User::factory()->create(['password' => 'old-password-1']);
    DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

    signIn($user)->put('/profile/password', [
        'current' => 'old-password-1',
        'password' => 'new-password-2',
        'password_confirmation' => 'new-password-2',
    ])->assertRedirect('/login?notice=password');

    $this->assertGuest();
    expect(Hash::check('new-password-2', $user->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'other-device')->exists())->toBeFalse();
});

it('needs the current password', function () {
    $user = User::factory()->create(['password' => 'old-password-1']);

    signIn($user)->from('/profile')->put('/profile/password', [
        'current' => 'wrong-password-1',
        'password' => 'new-password-2',
        'password_confirmation' => 'new-password-2',
    ])->assertSessionHasErrors(['current' => 'Your current password is incorrect.']);

    expect(Hash::check('old-password-1', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->login_attempts)->toBe(1);
});

it('applies the password rules', function () {
    $user = User::factory()->create(['password' => 'old-password-1']);

    signIn($user)->from('/profile')->put('/profile/password', [
        'current' => 'old-password-1',
        'password' => 'weak',
        'password_confirmation' => 'weak',
    ])->assertSessionHasErrors('password');
});
