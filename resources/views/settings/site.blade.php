@php
    $theme = old('default_theme', $settings->default_theme?->value ?? 'system');
    $themeLabels = ['system' => 'Follow each device (system setting)', 'light' => 'Light mode', 'dark' => 'Dark mode'];
@endphp

<x-layouts.app title="Branding &amp; Theme" heading="Branding &amp; theme" description="Company name, logo, colors and the default light/dark mode for everyone.">
    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('settings.site') }}" enctype="multipart/form-data" class="grid gap-6 xl:grid-cols-3" id="branding-form" data-dirty-guard>
        @csrf
        @method('PUT')
        <div class="space-y-6 xl:col-span-2">
            <fieldset class="card space-y-4 p-5">
                <legend class="px-1 text-sm font-semibold">Company</legend>
                <div>
                    <label class="label" for="company_name">Company name</label>
                    <input class="input" id="company_name" name="company_name" required maxlength="120" value="{{ old('company_name', $settings->company_name) }}" />
                </div>
                <div>
                    <label class="label" for="tagline">Tagline (optional)</label>
                    <input class="input" id="tagline" name="tagline" maxlength="160" value="{{ old('tagline', $settings->tagline) }}" placeholder="Shown under the name on the sign-in page" />
                </div>
                <div>
                    <span class="label">Logo</span>
                    <div class="flex flex-wrap items-center gap-4">
                        @if ($settings->logo_id)
                            <img src="{{ route('files.show', $settings->logo_id) }}" alt="Current logo" class="h-14 w-auto max-w-40 rounded-lg border border-line bg-surface-2 object-contain p-1" />
                        @else
                            <span class="text-sm text-muted">No logo — initials are shown instead.</span>
                        @endif
                        <input class="input max-w-sm" type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" aria-label="Upload logo" />
                        @if ($settings->logo_id)
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_logo" value="1" class="size-4" /> Remove logo</label>
                        @endif
                    </div>
                    <p class="field-help">PNG, JPG or WEBP up to 2 MB. A square or wide logo on a transparent background works best.</p>
                </div>
            </fieldset>

            <fieldset class="card p-5">
                <legend class="px-1 text-sm font-semibold">Color palette</legend>
                <div class="mb-4 flex flex-wrap gap-2">
                    @foreach ($presets as $name => $preset)
                        <button type="button" class="btn-secondary btn-sm" data-preset='@json($preset)'>
                            <span class="flex">
                                @foreach (['primary', 'accent', 'secondary'] as $key)
                                    <span class="-ml-1 size-3.5 rounded-full border-2 border-[var(--surface)] first:ml-0" style="background:{{ $preset[$key] }}"></span>
                                @endforeach
                            </span>
                            {{ $name }}
                        </button>
                    @endforeach
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($colorLabels as $key => $label)
                        <div>
                            <label class="label" for="c-{{ $key }}">{{ $label }}</label>
                            <div class="flex items-center gap-2">
                                <input type="color" class="h-10 w-12 cursor-pointer rounded-lg border border-line bg-surface p-1" value="{{ $palette[$key] }}" data-color-for="{{ $key }}" aria-label="{{ $key }} color picker" />
                                <input class="input font-mono" id="c-{{ $key }}" name="{{ $key }}" value="{{ $palette[$key] }}" pattern="#[0-9a-fA-F]{6}" required data-hex="{{ $key }}" />
                            </div>
                        </div>
                    @endforeach
                </div>
                @if ($warnings !== [])
                    <ul class="mt-4 space-y-1 rounded-lg bg-warning-soft px-4 py-3 text-sm text-warning">
                        @foreach ($warnings as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                @endif
            </fieldset>

            <fieldset class="card p-5">
                <legend class="px-1 text-sm font-semibold">Default appearance</legend>
                <div class="flex flex-wrap gap-4">
                    @foreach ($themes as $mode)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="default_theme" value="{{ $mode->value }}" @checked($mode->value === $theme) class="size-4" />
                            {{ $themeLabels[$mode->value] ?? $mode->value }}
                        </label>
                    @endforeach
                </div>
                <p class="field-help mt-2">Each person can still switch light/dark from the sidebar; their choice is remembered on their device.</p>
            </fieldset>
            <button class="btn-primary">Save branding</button>
        </div>

        <aside class="h-fit space-y-3 xl:sticky xl:top-6">
            <p class="text-sm font-semibold">Preview</p>
            <div id="preview" class="card space-y-3 p-5">
                <p class="text-lg font-semibold" data-preview-name>{{ old('company_name', $settings->company_name) }}</p>
                <div class="flex flex-wrap gap-2">
                    <span class="btn btn-sm" data-swatch="primary">Primary button</span>
                    <span class="btn btn-sm" data-swatch="accent">Accent</span>
                    <span class="btn btn-sm" data-swatch="secondary">Secondary</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="badge" data-swatch="success">Active</span>
                    <span class="badge" data-swatch="warning">Pending</span>
                    <span class="badge" data-swatch="danger">Terminated</span>
                </div>
                <p class="text-sm">A <span data-link>link in text</span> uses the primary color.</p>
            </div>
        </aside>
    </form>
</x-layouts.app>
