<?php

use App\Models\Notification;
use App\Models\User;

it('lists only my notifications', function () {
    $user = User::factory()->create();
    Notification::factory()->for($user)->create(['title' => 'Mine']);
    Notification::factory()->create(['title' => 'Someone else']);

    signIn($user)->get('/notifications')->assertSuccessful()->assertSee('Mine')->assertDontSee('Someone else');
});

it('marks one read and follows its link', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->for($user)->create(['link' => '/leave/5']);

    signIn($user)->post("/notifications/{$notification->id}/read")->assertRedirect('/leave/5');

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('never follows an unsafe link', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->for($user)->create(['link' => 'https://evil.example']);

    signIn($user)->post("/notifications/{$notification->id}/read")->assertRedirect('/');
});

it('stays on the page when there is no link', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->for($user)->create();

    signIn($user)->post("/notifications/{$notification->id}/read")->assertRedirect('/notifications');
});

it('refuses someone else\'s notification', function () {
    $notification = Notification::factory()->create();

    signIn(User::factory()->create())->post("/notifications/{$notification->id}/read")->assertForbidden();

    expect($notification->fresh()->read_at)->toBeNull();
});

it('marks all of mine as read and no one else\'s', function () {
    $user = User::factory()->create();
    Notification::factory()->count(2)->for($user)->create();
    $other = Notification::factory()->create();

    signIn($user)->post('/notifications/read-all')->assertRedirect('/notifications');

    expect($user->hrNotifications()->whereNull('read_at')->count())->toBe(0)
        ->and($other->fresh()->read_at)->toBeNull();
});
