<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\CertificateTemplateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataController;
use App\Http\Controllers\EmailServerController;
use App\Http\Controllers\EmailSettingsController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmploymentPeriodController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\LeaveSettingsController;
use App\Http\Controllers\LoginVerificationController;
use App\Http\Controllers\MailQueueController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PayrollImportController;
use App\Http\Controllers\PayslipController;
use App\Http\Controllers\PayslipSettingsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RequirementController;
use App\Http\Controllers\RequirementDeadlineController;
use App\Http\Controllers\SetPasswordController;
use App\Http\Controllers\SiteSettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WellnessLeaveController;
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

// Fallback for hosts without a usable scheduler. The key travels in a header, out of access logs.
Route::get('/internal/queue', MailQueueController::class)->middleware('throttle:auth-posts')->name('internal.queue');

Route::middleware(RequireActiveSession::class)->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::prefix('employees')->name('employees.')->controller(EmployeeController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/new', 'create')->name('create');
        Route::get('/{employee}', 'show')->name('show')->whereNumber('employee');
        Route::put('/{employee}', 'update')->name('update')->whereNumber('employee');
        Route::delete('/{employee}', 'destroy')->name('destroy')->whereNumber('employee');
        Route::get('/{employee}/edit', 'edit')->name('edit')->whereNumber('employee');
    });

    Route::prefix('employees/{employee}/employment')->name('employment.')->whereNumber(['employee', 'period'])->controller(EmploymentPeriodController::class)->group(function () {
        Route::post('/', 'hire')->name('hire');
        Route::post('/separate', 'separate')->name('separate');
        Route::put('/{period}', 'update')->name('update');
        Route::delete('/{period}', 'destroy')->name('destroy');
    });

    Route::prefix('branches')->name('branches.')->controller(BranchController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/new', 'create')->name('create');
        Route::get('/{branch}', 'show')->name('show')->whereNumber('branch');
        Route::put('/{branch}', 'update')->name('update')->whereNumber('branch');
        Route::delete('/{branch}', 'destroy')->name('destroy')->whereNumber('branch');
        Route::get('/{branch}/edit', 'edit')->name('edit')->whereNumber('branch');
    });

    Route::prefix('onboarding')->name('onboarding.')->controller(ApplicationController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/new', 'create')->name('create');
        Route::get('/{application}', 'show')->name('show')->whereNumber('application');
        Route::put('/{application}', 'update')->name('update')->whereNumber('application');
        Route::delete('/{application}', 'destroy')->name('destroy')->whereNumber('application');
        Route::post('/{application}/requirement', 'updateRequirement')->name('requirement')->whereNumber('application');
        Route::post('/{application}/send-requirements', 'sendRequirements')->name('send-requirements')->whereNumber('application');
    });

    Route::prefix('leave')->name('leave.')->group(function () {
        Route::controller(LeaveSettingsController::class)->group(function () {
            Route::get('/settings', 'edit')->name('settings');
            Route::put('/settings/allowance', 'updateAllowance')->name('allowance');
            Route::post('/settings/holidays', 'storeHoliday')->name('holidays.store');
            Route::delete('/settings/holidays/{holiday}', 'destroyHoliday')->name('holidays.destroy');
        });

        Route::controller(WellnessLeaveController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::get('/new', 'create')->name('create');
            Route::get('/preview', 'preview')->name('preview');
            Route::get('/{leave}', 'edit')->name('edit')->whereNumber('leave');
            Route::put('/{leave}', 'update')->name('update')->whereNumber('leave');
            Route::delete('/{leave}', 'destroy')->name('destroy')->whereNumber('leave');
        });
    });

    Route::prefix('requirements')->name('requirements.')->group(function () {
        Route::controller(RequirementDeadlineController::class)->group(function () {
            Route::get('/deadlines', 'edit')->name('deadlines');
            Route::put('/deadlines/usual', 'updateUsual')->name('deadlines.usual');
            Route::put('/deadlines/overrides', 'updateOverrides')->name('deadlines.overrides');
        });

        Route::controller(RequirementController::class)->group(function () {
            Route::get('/', 'overview')->name('overview');

            Route::prefix('{type}')->whereIn('type', ['itr', 'sworn-declaration', 'pds', 'ipcr'])->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::get('/new', 'create')->name('create');
                Route::post('/generate', 'generate')->name('generate');
                Route::put('/deadline', 'updateDeadline')->name('deadline');
                Route::get('/{record}', 'edit')->name('edit')->whereNumber('record');
                Route::put('/{record}', 'update')->name('update')->whereNumber('record');
                Route::delete('/{record}', 'destroy')->name('destroy')->whereNumber('record');
            });
        });
    });

    Route::prefix('certificates')->name('certificates.')->group(function () {
        Route::prefix('templates')->name('templates.')->controller(CertificateTemplateController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::get('/{template}', 'edit')->name('edit')->whereNumber('template');
            Route::put('/{template}', 'update')->name('update')->whereNumber('template');
            Route::delete('/{template}', 'destroy')->name('destroy')->whereNumber('template');
            Route::post('/{template}/duplicate', 'duplicate')->name('duplicate')->whereNumber('template');
            Route::post('/{template}/toggle', 'toggle')->name('toggle')->whereNumber('template');
        });

        Route::controller(CertificateController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::get('/new', 'create')->name('create');
            Route::get('/preview', 'preview')->name('preview');
            Route::get('/{certificate}', 'show')->name('show')->whereNumber('certificate');
            Route::delete('/{certificate}', 'destroy')->name('destroy')->whereNumber('certificate');
            Route::post('/{certificate}/void', 'void')->name('void')->whereNumber('certificate');
            Route::get('/{certificate}/pdf', 'pdf')->name('pdf')->whereNumber('certificate');
            Route::get('/{certificate}/print', 'print')->name('print')->whereNumber('certificate');
        });
    });

    Route::prefix('payroll')->name('payroll.')->group(function () {
        Route::get('/settings', [PayslipSettingsController::class, 'edit'])->name('settings');
        Route::put('/settings', [PayslipSettingsController::class, 'update']);

        Route::prefix('payslips/{payslip}')->name('payslips.')->whereNumber('payslip')->controller(PayslipController::class)->group(function () {
            Route::get('/', 'show')->name('show');
            Route::put('/', 'update')->name('update');
            Route::delete('/', 'destroy')->name('destroy');
            Route::post('/resend', 'resend')->name('resend');
            Route::get('/print', 'print')->name('print');
            Route::get('/pdf', 'pdf')->name('pdf');
        });

        Route::controller(PayrollController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::get('/new', 'create')->name('create');

            Route::prefix('{period}')->whereNumber('period')->group(function () {
                Route::get('/', 'show')->name('show');
                Route::put('/', 'update')->name('update');
                Route::delete('/', 'destroy')->name('destroy');
                Route::get('/edit', 'edit')->name('edit');
                Route::post('/generate', 'generate')->name('generate');
                Route::put('/grid', 'saveGrid')->name('grid');
                Route::post('/release', 'release')->name('release');
                Route::delete('/payslips', 'destroyPayslips')->name('payslips.destroy');
                Route::get('/print', 'print')->name('print');
                Route::get('/export', 'export')->name('export');
            });
        });

        Route::prefix('{period}/import')->name('import.')->whereNumber('period')->controller(PayrollImportController::class)->group(function () {
            Route::post('/', 'store')->name('store');
            Route::post('/commit', 'commit')->name('commit');
        });
    });

    Route::prefix('messages')->name('messages.')->controller(MessageController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/new', 'create')->name('create');
        Route::post('/preview', 'preview')->name('preview');
        Route::get('/{message}', 'show')->name('show')->whereNumber('message');
        Route::post('/{message}/process', 'process')->name('process')->whereNumber('message');
        Route::post('/{message}/retry', 'retry')->name('retry')->whereNumber('message');
    });

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SiteSettingsController::class, 'edit'])->name('site');
        Route::put('/', [SiteSettingsController::class, 'update']);

        Route::prefix('notifications')->controller(EmailSettingsController::class)->group(function () {
            Route::get('/', 'edit')->name('notifications');
            Route::put('/', 'update');
            Route::post('/test', 'test')->name('notifications.test');
            Route::post('/rotate-key', 'rotateKey')->name('notifications.rotate');
        });

        Route::prefix('templates')->name('templates.')->controller(EmailTemplateController::class)->group(function () {
            Route::post('/', 'store')->name('store');
            Route::get('/new', 'create')->name('create');
            Route::get('/{template}', 'edit')->name('edit')->whereNumber('template');
            Route::put('/{template}', 'update')->name('update')->whereNumber('template');
            Route::delete('/{template}', 'destroy')->name('destroy')->whereNumber('template');
        });

        Route::prefix('email-server')->middleware(EnsureSystemAdmin::class)->controller(EmailServerController::class)->group(function () {
            Route::get('/', 'edit')->name('email-server');
            Route::put('/', 'update');
            Route::post('/test', 'test')->name('email-server.test');
        });
    });

    Route::prefix('data')->name('data.')->controller(DataController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/export', 'export')->name('export');
        Route::get('/template', 'template')->name('template');
        Route::post('/import', 'preview')->name('import');
        Route::post('/import/commit', 'commit')->name('import.commit');
    });

    Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');

    Route::prefix('notifications')->name('notifications.')->controller(NotificationController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/read-all', 'readAll')->name('read-all');
        Route::post('/{notification}/read', 'read')->name('read')->whereNumber('notification');
    });

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
