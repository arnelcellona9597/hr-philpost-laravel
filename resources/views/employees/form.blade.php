@php
    $title = $employee ? "Edit {$employee->full_name}" : 'Add employee';
    $heading = $application ? "Add {$application->applicant_name} as an employee" : $title;
    $back = match (true) {
        $employee !== null => ['href' => route('employees.show', $employee), 'label' => 'Back to profile'],
        $application !== null => ['href' => route('onboarding.show', $application), 'label' => 'Back to application'],
        default => ['href' => route('employees.index'), 'label' => 'Employees'],
    };
@endphp

<x-layouts.app :title="$title" :heading="$heading" :back="$back" :description="$application ? 'From application '.$application->application_id.'. Check the split of first and last name before saving.' : null">
    <x-module-form
        :fields="$fields"
        :values="$values"
        :choices="['station' => $branches, 'unit' => $units]"
        :suggestions="['position' => $positions]"
        :submit-label="$employee ? 'Save changes' : 'Add employee'"
        :cancel-href="$employee ? route('employees.show', $employee) : route('employees.index')"
        :wide="['address', 'emergency_address', 'professional_eligibility']"
        :spoof="$employee ? 'PUT' : null"
        :action="$employee ? route('employees.update', $employee) : route('employees.store', array_filter(['fromApplication' => $application?->id]))"
        enctype="multipart/form-data"
    >
        <x-slot:before>
            <fieldset class="card p-5">
                <legend class="px-1 text-sm font-semibold">Profile picture</legend>
                <div class="flex flex-wrap items-center gap-4">
                    @if ($employee?->profile_picture_id)
                        <img src="{{ url("/files/{$employee->profile_picture_id}") }}" alt="Current profile picture" class="size-16 rounded-full object-cover" />
                    @endif
                    <div class="min-w-64 flex-1">
                        <input class="input" type="file" name="photo" accept="image/png,image/jpeg,image/webp,image/gif" aria-describedby="photo-help" />
                        @error('photo')
                            <p class="field-error">{{ $message }}</p>
                        @else
                            <p class="field-help" id="photo-help">{{ $employee?->profile_picture_id ? 'Choose a new image to replace the current one (up to 5 MB).' : 'Optional. PNG, JPG, WEBP or GIF up to 5 MB.' }}</p>
                        @enderror
                    </div>
                    @if ($employee?->profile_picture_id)
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="removePhoto" class="size-4" /> Remove picture</label>
                    @endif
                </div>
            </fieldset>
        </x-slot:before>
    </x-module-form>
</x-layouts.app>
