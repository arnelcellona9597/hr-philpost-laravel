{{-- Live working days and remaining balance under the leave form. Behaviour is in resources/js/forms.js. --}}
@props(['excludeId' => null])

<div id="leave-preview" class="card p-4 text-sm" data-exclude="{{ $excludeId }}" aria-live="polite">
    <p class="text-muted">Pick the employee and inclusive dates to see the working days and remaining balance.</p>
</div>
