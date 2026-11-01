<?php

namespace App\Http\Controllers;

use App\Enums\ThemeMode;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Media;
use App\Models\SiteSetting;
use App\Services\MediaStore;
use App\Support\Colors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SiteSettingsController extends Controller
{
    use RespondsToForms;

    private const LOGO_MAX_BYTES = 2 * 1024 * 1024;

    private const COLOR_LABELS = [
        'primary' => 'Primary (buttons, links, highlights)',
        'secondary' => 'Secondary',
        'accent' => 'Accent',
        'success' => 'Success',
        'warning' => 'Warning',
        'danger' => 'Danger',
    ];

    public function edit(): View
    {
        $settings = SiteSetting::current();

        Gate::authorize('update', $settings);

        $palette = array_merge(Colors::resolve($settings->palette), array_intersect_key((array) session()->getOldInput(), self::COLOR_LABELS));

        return view('settings.site', [
            'settings' => $settings,
            'palette' => $palette,
            'colorLabels' => self::COLOR_LABELS,
            'presets' => ['Default (Blue, Red, Yellow)' => SiteSetting::DEFAULT_PALETTE, ...Colors::PRESETS],
            'warnings' => Colors::contrastWarnings($palette),
            'themes' => ThemeMode::cases(),
        ]);
    }

    public function update(Request $request, MediaStore $media): RedirectResponse
    {
        $settings = SiteSetting::current();

        Gate::authorize('update', $settings);

        $name = trim((string) $request->input('company_name'));
        $palette = [];

        foreach (array_keys(self::COLOR_LABELS) as $key) {
            $palette[$key] = mb_strtolower(trim((string) $request->input($key)));
        }

        $invalidColors = array_keys(array_filter($palette, fn (string $color): bool => ! Colors::isHex($color)));

        if ($name === '') {
            return $this->invalid(['company_name' => 'Enter the company name.']);
        }

        if ($invalidColors !== []) {
            return $this->refused('Use hex colors like #1d4ed8 for: '.implode(', ', $invalidColors).'.');
        }

        $previousLogo = $settings->logo;
        $file = $request->file('logo');
        $logoId = $settings->logo_id;

        try {
            if ($file instanceof UploadedFile) {
                $logo = $media->store($file, "{$name} logo", MediaStore::IMAGE_TYPES, self::LOGO_MAX_BYTES);
                $logo->forceFill(['is_public' => true])->save();
                $logoId = $logo->id;
            } elseif ($request->boolean('remove_logo')) {
                $logoId = null;
            }
        } catch (UserFacingException $exception) {
            return $this->refused($exception->getMessage());
        }

        $settings->forceFill([
            'company_name' => mb_substr($name, 0, 120),
            'tagline' => mb_substr(trim((string) $request->input('tagline')), 0, 160) ?: null,
            'default_theme' => ThemeMode::tryFrom((string) $request->input('default_theme')) ?? ThemeMode::System,
            'palette' => $palette,
            'logo_id' => $logoId,
        ])->save();

        if ($previousLogo instanceof Media && $previousLogo->id !== $logoId) {
            $media->delete($previousLogo);
        }

        return $this->flash(redirect()->route('settings.site'), 'Branding saved. The new look applies to everyone right away.');
    }
}
