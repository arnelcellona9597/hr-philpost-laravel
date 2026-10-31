<?php

namespace App\Providers;

use App\View\Composers\BrandingComposer;
use App\View\Composers\ShellComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer(['components.layouts.base', 'components.layouts.auth'], BrandingComposer::class);
        View::composer('components.layouts.app', ShellComposer::class);

        RateLimiter::for('auth-posts', fn (Request $request) => Limit::perMinutes(15, 20)->by($request->ip().'|'.$request->path()));
        RateLimiter::for('account-posts', fn (Request $request) => Limit::perMinutes(15, 120)->by($request->ip().'|account'));
    }
}
