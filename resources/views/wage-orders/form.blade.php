<x-layouts.app :title="$order ? 'Edit wage order' : 'New wage order'" :heading="$order ? 'Edit wage order' : 'New wage order'" :back="['href' => route('wage-orders.index'), 'label' => 'Wage orders']">
    <x-module-form
        :fields="$fields"
        :values="$values"
        :cancel-href="route('wage-orders.index')"
        :spoof="$order ? 'PUT' : null"
        :submit-label="$order ? 'Save changes' : 'Add wage order'"
        :action="$order ? route('wage-orders.update', $order) : route('wage-orders.store')"
    />
</x-layouts.app>
