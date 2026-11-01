<x-layouts.app title="Contract settings" heading="Officials and wages" :back="['href' => route('contracts.index'), 'label' => 'Contracts']" description="The four officials who sign every contract, and the wage wording shared by all of them. Changes apply to contracts issued from now on; issued ones keep what they were issued with.">
    @if ($errors->has('form'))
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first('form') }}</div>
    @endif

    <form method="post" action="{{ route('contracts.settings') }}" class="space-y-6" data-dirty-guard>
        @csrf
        @method('PUT')

        <section class="card grid gap-4 p-5 sm:grid-cols-2">
            <h2 class="text-sm font-semibold sm:col-span-2">Signing officials</h2>
            @foreach ($officials as $key => $default)
                <div>
                    <label class="label" for="{{ $key }}_name">{{ $default }} — name</label>
                    <input class="input" id="{{ $key }}_name" name="{{ $key }}_name" maxlength="120" value="{{ old("{$key}_name", $settings->{"{$key}_name"}) }}" />
                </div>
                <div>
                    <label class="label" for="{{ $key }}_title">{{ $default }} — title on the contract</label>
                    <input class="input" id="{{ $key }}_title" name="{{ $key }}_title" maxlength="160" value="{{ old("{$key}_title", $settings->{"{$key}_title"} ?: $default) }}" />
                </div>
            @endforeach
            <p class="text-xs text-muted sm:col-span-2">Their initials are printed from these names in the page footer of every page.</p>
        </section>

        <section class="card grid gap-4 p-5 sm:grid-cols-2">
            <h2 class="text-sm font-semibold sm:col-span-2">Wages and place</h2>
            <div class="sm:col-span-2">
                <label class="label" for="wage_order">Wage order reference</label>
                <input class="input" id="wage_order" name="wage_order" maxlength="255" value="{{ old('wage_order', $settings->wage_order) }}" placeholder="e.g. the wage order the daily rate follows" />
            </div>
            <div>
                <label class="label" for="premium_percent">Premium (%)</label>
                <input class="input" id="premium_percent" name="premium_percent" type="number" min="0" max="100" value="{{ old('premium_percent', $settings->premium_percent) }}" />
            </div>
            <div>
                <label class="label" for="default_place_of_service">Default place of service</label>
                <input class="input" id="default_place_of_service" name="default_place_of_service" maxlength="160" value="{{ old('default_place_of_service', $settings->default_place_of_service) }}" />
            </div>
        </section>

        <button class="btn-primary"><x-icon name="check" /> Save</button>
    </form>
</x-layouts.app>
