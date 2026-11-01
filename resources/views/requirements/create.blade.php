<x-layouts.app :title="'Add '.$module['title']" :heading="'Add '.$module['title'].' record'" :back="['href' => route('requirements.index', [$type, 'year' => $year]), 'label' => $module['title']]">
    <div class="max-w-5xl">
        <x-module-form
            :fields="$fields"
            :values="$values"
            :choices="['employee' => $employees]"
            :cancel-href="route('requirements.index', [$type, 'year' => $year])"
            submit-label="Save record"
            :action="route('requirements.store', [$type, 'year' => $year])"
        >
            <p class="text-sm text-muted">Name, station, job title and TIN come from the employee record.</p>
        </x-module-form>
    </div>
</x-layouts.app>
