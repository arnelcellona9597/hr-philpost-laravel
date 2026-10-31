<?php

use App\Http\Middleware\RequireActiveSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Act as a user with a live session, as the sign-in flow leaves it.
 */
function signIn(User $user): TestCase
{
    return test()->actingAs($user)->withSession([RequireActiveSession::EXPIRES_AT_KEY => now()->addHours(12)->getTimestamp()]);
}
