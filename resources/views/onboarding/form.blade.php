<x-layouts.app title="New application" :back="['href' => route('onboarding.index'), 'label' => 'Onboarding tracker']">
    <x-module-form
        :fields="$fields"
        :values="$values"
        :choices="['station' => $branches]"
        :cancel-href="route('onboarding.index')"
        submit-label="Add application"
        :action="route('onboarding.store')"
    />
</x-layouts.app>
