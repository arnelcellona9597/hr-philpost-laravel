@php
    $title = $branch ? "Edit {$branch->name}" : 'Add branch';
    $back = $branch ? ['href' => route('branches.show', $branch), 'label' => $branch->name] : ['href' => route('branches.index'), 'label' => 'Branches'];
@endphp

<x-layouts.app :title="$title" :back="$back">
    <x-module-form
        :fields="$fields"
        :values="$values"
        :submit-label="$branch ? 'Save changes' : 'Add branch'"
        :cancel-href="$back['href']"
        :wide="['address']"
        :spoof="$branch ? 'PUT' : null"
        :action="$branch ? route('branches.update', $branch) : route('branches.store')"
    />
</x-layouts.app>
