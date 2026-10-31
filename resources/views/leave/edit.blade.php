<x-layouts.app title="Edit leave" :heading="'Leave of '.$leave->first_name.' '.$leave->last_name" :back="['href' => route('leave.index'), 'label' => 'Wellness leave']">
    <x-slot:actions>
        <form method="post" action="{{ route('leave.destroy', $leave) }}" data-confirm="Delete this leave filing? The days return to the employee's balance.">
            @csrf
            @method('DELETE')
            <button class="btn-danger"><x-icon name="trash" /> Delete</button>
        </form>
    </x-slot:actions>
    <div class="max-w-4xl">
        <x-module-form
            :fields="$fields"
            :values="$values"
            :choices="['employee' => $employees]"
            :cancel-href="route('leave.index')"
            submit-label="Save changes"
            spoof="PUT"
            :action="route('leave.update', $leave)"
        >
            <x-leave.leave-preview :exclude-id="$leave->id" />
        </x-module-form>
    </div>
</x-layouts.app>
