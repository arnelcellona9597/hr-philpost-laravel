<?php

use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\LoginVerificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SetPasswordController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureSystemAdmin;
use App\Http\Middleware\RequireActiveSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:auth-posts');

    Route::get('/login/verify', [LoginVerificationController::class, 'show'])->name('login.verify');

    Route::middleware('throttle:auth-posts')->group(function () {
        Route::post('/login/verify', [LoginVerificationController::class, 'store']);
        Route::post('/login/verify/resend', [LoginVerificationController::class, 'resend'])->name('login.verify.resend');
        Route::post('/login/verify/cancel', [LoginVerificationController::class, 'cancel'])->name('login.verify.cancel');
    });
});

Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.forgot');
Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->middleware('throttle:auth-posts');
Route::get('/set-password', [SetPasswordController::class, 'edit'])->name('password.set');
Route::post('/set-password', [SetPasswordController::class, 'update'])->middleware('throttle:auth-posts');

// Old reset links point here; password links are handled by /set-password.
Route::get('/reset-password', fn (Request $request) => redirect()->route('password.set', ['token' => (string) $request->query('token')]));

// Public sign-up is disabled: HR accounts are created by a System Admin.
Route::match(['get', 'post'], '/register', fn () => redirect()->route('login', ['notice' => 'no-signup']));

Route::get('/files/{media}', [FileController::class, 'show'])->name('files.show');

Route::middleware(RequireActiveSession::class)->group(function () {
    Route::view('/', 'dashboard')->name('dashboard');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::middleware('throttle:account-posts')->group(function () {
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile')->withoutMiddleware('throttle:account-posts');
        Route::put('/profile/name', [ProfileController::class, 'updateName'])->name('profile.name');
        Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');
        Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

        Route::middleware(EnsureSystemAdmin::class)->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('users.index')->withoutMiddleware('throttle:account-posts');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('/users/{user}/unlock', [UserController::class, 'unlock'])->name('users.unlock');
            Route::post('/users/{user}/link', [UserController::class, 'sendLink'])->name('users.link');
            Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        });
    });
});
