<x-layouts.print :title="'Employee profile — '.$employee->full_name" paper="A4" :back="route('employees.show', $employee)">
    <x-employees.profile :employee="$employee" :fields="$fields" :employment="$employment" :pay="$pay" :position-changes="$positionChanges" :site="$site" />
</x-layouts.print>
