@props(['field', 'value' => '', 'error' => null, 'choices' => [], 'disabled' => false, 'suggestions' => []])

@php
    $id = "f-{$field['name']}";
    $kind = $field['kind'];
    $help = $field['help'] ?? null;
    $required = ($field['required'] ?? false) && ! ($field['fromEmployee'] ?? false);
    $describedBy = $error ? "{$id}-err" : ($help ? "{$id}-help" : null);
    $listId = $suggestions ? "{$id}-list" : null;
    $isNumeric = in_array($kind, ['number', 'year'], true);
    $type = match ($kind) {
        'date' => 'date',
        'email' => 'email',
        'number', 'year' => 'number',
        'phone' => 'tel',
        default => 'text',
    };
    $placeholder = $kind === 'govId'
        ? ['sss' => '00-0000000-0', 'pagibig' => '0000-0000-0000', 'philhealth' => '00-000000000-0', 'tin' => '000-000-000-000'][$field['idKind'] ?? ''] ?? null
        : null;
    $common = new \Illuminate\View\ComponentAttributeBag(array_filter([
        'id' => $id,
        'name' => $field['name'],
        'disabled' => $disabled,
        'aria-invalid' => $error ? 'true' : null,
        'aria-describedby' => $describedBy,
    ]));
@endphp

<div {{ $attributes }}>
    <label class="label" for="{{ $id }}">
        {{ $field['label'] }}@if ($required)<span class="text-danger" aria-hidden="true"> *</span>@endif
    </label>

    @if ($kind === 'textarea')
        <textarea class="input min-h-20" rows="3" {{ $common }}>{{ $value }}</textarea>
    @elseif ($kind === 'select')
        <select class="input" @required($required) {{ $common }}>
            <option value="">{{ $required ? 'Select…' : '—' }}</option>
            @foreach ($field['options'] ?? [] as $option)
                <option value="{{ $option }}" @selected($option === (string) $value)>{{ $option }}</option>
            @endforeach
        </select>
    @elseif ($kind === 'branch' || $kind === 'employee')
        <select class="input" @required($required) @if ($kind === 'employee') data-combobox @endif {{ $common }}>
            <option value="">{{ $required ? 'Select…' : '—' }}</option>
            @foreach ($choices as $choice)
                <option value="{{ $choice['value'] }}" @selected((string) $choice['value'] === (string) $value)>{{ $choice['label'] }}</option>
            @endforeach
        </select>
    @else
        <input
            class="input"
            type="{{ $type }}"
            value="{{ $value }}"
            autocomplete="off"
            @required($required)
            @if ($kind === 'govId') inputmode="numeric" @endif
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            @if ($listId) list="{{ $listId }}" @endif
            @if ($kind === 'year') min="2000" max="2100" @else
                @isset($field['min']) min="{{ $field['min'] }}" @endisset
                @isset($field['max']) max="{{ $field['max'] }}" @endisset
            @endif
            @if ($isNumeric || isset($field['step'])) step="{{ $field['step'] ?? 1 }}" @endif
            {{ $common }}
        />
    @endif

    @if ($listId)
        <datalist id="{{ $listId }}">
            @foreach ($suggestions as $suggestion)
                <option value="{{ $suggestion }}"></option>
            @endforeach
        </datalist>
    @endif

    @if ($error)
        <p class="field-error" id="{{ $id }}-err">{{ $error }}</p>
    @elseif ($help)
        <p class="field-help" id="{{ $id }}-help">{{ $help }}</p>
    @endif
</div>
