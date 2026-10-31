<?php

namespace App\View\Composers;

use App\Enums\ThemeMode;
use App\Models\SiteSetting;
use App\Support\Colors;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Binds the company name, logo, palette and theme that every page needs.
 */
class BrandingComposer
{
    public function __construct(protected Request $request) {}

    /**
     * Bind data to the view.
     */
    public function compose(View $view): void
    {
        $settings = SiteSetting::current();
        $palette = Colors::resolve($settings->palette);

        $view->with([
            'company' => $settings->company_name,
            'tagline' => $settings->tagline,
            'logoId' => $settings->logo_id,
            'palette' => $palette,
            'onPrimary' => Colors::readableOn($palette['primary']),
            'explicitTheme' => $this->explicitTheme($settings),
        ]);
    }

    /**
     * The visitor's choice beats the site default. Null means follow the device.
     */
    private function explicitTheme(SiteSetting $settings): ?string
    {
        $theme = ThemeMode::tryFrom((string) $this->request->cookie('hr-theme')) ?? $settings->default_theme;

        return $theme === ThemeMode::System ? null : $theme->value;
    }
}
