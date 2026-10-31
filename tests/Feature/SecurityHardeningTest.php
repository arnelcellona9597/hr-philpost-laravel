<?php

use App\Mail\SystemMessage;
use App\Models\Media;
use App\Models\NotificationSetting;
use App\Models\SmtpSetting;
use App\Models\User;
use App\Services\DirectMailer;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

it('sends security headers on every page', function () {
    $this->get('/login')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('Content-Security-Policy');
});

it('locks the content security policy down', function () {
    $policy = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($policy)->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->toContain("frame-ancestors 'self'");
});

it('leaves HSTS off while the site is on http', function () {
    config(['app.url' => 'http://localhost:8000']);

    $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
});

it('sends HSTS on https', function () {
    config(['app.url' => 'https://hr.example.test']);

    $this->get('/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('keeps signed-in pages out of caches', function () {
    signIn(User::factory()->create())->get('/profile')->assertHeader('Cache-Control', 'no-store, private');
});

it('keeps CSRF protection on form routes', function () {
    $route = app('router')->getRoutes()->match(request()->create('/login', 'POST'));

    expect(app('router')->gatherRouteMiddleware($route))->toContain(PreventRequestForgery::class);
});

function storedFile(array $state = []): Media
{
    Storage::fake('local');
    $media = Media::factory()->create(['path' => 'media/a.png', 'filename' => 'a.png', ...$state]);
    Storage::disk('local')->put('media/a.png', 'contents');

    return $media;
}

it('serves private files to staff without caching', function () {
    $media = storedFile();

    signIn(User::factory()->create())->get("/files/{$media->id}")
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");

    expect($this->get("/files/{$media->id}")->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('hides private files from visitors and disabled accounts', function () {
    $media = storedFile();

    $this->get("/files/{$media->id}")->assertNotFound();
    $this->actingAs(User::factory()->disabled()->create())->get("/files/{$media->id}")->assertNotFound();
});

it('serves public files to visitors with a short cache', function () {
    $media = storedFile();
    $media->forceFill(['is_public' => true])->save();

    $headers = $this->get("/files/{$media->id}")->assertSuccessful()->headers;

    expect($headers->get('Cache-Control'))->toContain('public')->toContain('max-age=300');
});

it('never keeps a media file under the public folder', function () {
    expect(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'));
});

it('answers 404 for missing files', function () {
    signIn(User::factory()->create())->get('/files/999')->assertNotFound();
});

it('does not mail or log codes in production without an SMTP server', function () {
    Mail::fake();
    Log::spy();
    $this->app['env'] = 'production';

    expect(app(DirectMailer::class)->isConfigured())->toBeFalse()
        ->and(app(DirectMailer::class)->send('a@example.test', 'Your sign-in code: 123456', 'code 123456'))->toBeFalse();

    Mail::assertNothingSent();
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('error');
});

it('treats the saved server as the source of truth under the smtp mailer', function () {
    config(['mail.default' => 'smtp']);

    expect(app(DirectMailer::class)->isConfigured())->toBeFalse();

    SmtpSetting::current()->update(['host' => 'smtp.example.test', 'from_address' => 'hr@example.test']);

    expect(app(DirectMailer::class)->isConfigured())->toBeTrue();
});

it('maps the security choice to the transport', function (string $security, array $expected) {
    $settings = SmtpSetting::current();
    $settings->update(['host' => 'smtp.example.test', 'port' => 587, 'security' => $security, 'username' => 'u', 'password' => 'p']);

    expect(DirectMailer::transportConfig($settings->fresh()))->toMatchArray($expected + ['host' => 'smtp.example.test', 'port' => 587, 'password' => 'p']);
})->with([
    ['ssl', ['scheme' => 'smtps']],
    ['starttls', ['scheme' => 'smtp', 'require_tls' => true]],
    ['none', ['scheme' => 'smtp', 'auto_tls' => false]],
]);

it('records a failed send without blocking the caller', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('connection refused'));

    expect(app(DirectMailer::class)->send('a@example.test', 'S', 'B'))->toBeFalse()
        ->and(NotificationSetting::current()->smtp_last_error)->toBe('connection refused');
});

it('warns admins with detail and staff without', function () {
    NotificationSetting::current()->forceFill(['smtp_last_error' => 'relay denied at 10.0.0.5'])->save();

    signIn(User::factory()->systemAdmin()->create())->get('/profile')->assertSee('relay denied at 10.0.0.5');

    auth()->logout();

    signIn(User::factory()->create())->get('/profile')->assertDontSee('relay denied')->assertSee('There is a problem sending email.');
});

it('keeps links intact in the text part and escapes the html part', function () {
    $mail = new SystemMessage('Subject', "Hello <b>\nline https://example.test/a?x=1&y=2");

    $mail->assertSeeInText('Hello <b>');
    $mail->assertSeeInText('https://example.test/a?x=1&y=2');
    $mail->assertSeeInHtml('Hello &lt;b&gt;<br>line', false);
});
