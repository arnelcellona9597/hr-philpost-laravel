@php
    $title = $period ? "Edit {$period->name}" : 'New payroll period';
    $back = $period ? ['href' => route('payroll.show', $period), 'label' => $period->name] : ['href' => route('payroll.index'), 'label' => 'Payroll'];
@endphp

<x-layouts.app :title="$title" :back="$back">
    <div class="max-w-4xl">
        <x-module-form
            :fields="$fields"
            :values="$values"
            :submit-label="$period ? 'Save changes' : 'Create period'"
            :cancel-href="$back['href']"
            :spoof="$period ? 'PUT' : null"
            :action="$period ? route('payroll.update', $period) : route('payroll.store')"
        />
    </div>
</x-layouts.app>
