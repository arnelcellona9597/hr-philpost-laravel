{{-- Generic create/edit form for a module: groups fields by section and renders them in a grid. --}}
@props([
    'fields',
    'values' => [],
    'errors' => [],
    'formError' => null,
    'choices' => [],
    'suggestions' => [],
    'submitLabel' => 'Save',
    'cancelHref',
    'locked' => [],
    'wide' => [],
    'before' => null,
])

@php
    $editable = collect($fields)->reject(fn (array $field): bool => $field['computed'] ?? false);
    $sections = $editable->groupBy(fn (array $field): string => $field['section'] ?? '');
@endphp

<form method="post" class="space-y-6" data-dirty-guard novalidate {{ $attributes }}>
    @csrf
    @if ($formError)
        <div class="rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ $formError }}</div>
    @endif
    {{ $before }}
    @foreach ($sections as $section => $sectionFields)
        <fieldset class="card p-5">
            @if ($section !== '')
                <legend class="px-1 text-sm font-semibold text-fg">{{ $section }}</legend>
            @endif
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($sectionFields as $field)
                    <x-form-field
                        :field="$field"
                        :value="$values[$field['name']] ?? ''"
                        :error="$errors[$field['name']] ?? null"
                        :choices="$choices[$field['name']] ?? []"
                        :suggestions="$suggestions[$field['name']] ?? []"
                        :disabled="in_array($field['name'], $locked, true)"
                        @class(['sm:col-span-2 lg:col-span-3' => $field['kind'] === 'textarea' || in_array($field['name'], $wide, true)])
                    />
                @endforeach
            </div>
        </fieldset>
    @endforeach
    {{ $slot }}
    <div class="flex flex-wrap items-center gap-3">
        <button class="btn-primary">{{ $submitLabel }}</button>
        <a href="{{ $cancelHref }}" class="btn-secondary">Cancel</a>
        <p class="text-xs text-muted"><span class="text-danger">*</span> required</p>
    </div>
</form>
