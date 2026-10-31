<?php

namespace App\Providers;

use App\View\Composers\BrandingComposer;
use App\View\Composers\ShellComposer;
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
    }
}
