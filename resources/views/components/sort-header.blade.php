{{-- A sortable column header that keeps the current filters in the URL. --}}
@props(['field', 'label'])

@php
    $current = (string) request()->query('sort', '');
    $descending = $current === "-{$field}";
    $active = $descending || $current === $field;
    $query = array_merge(request()->except('page'), ['sort' => $active && ! $descending ? "-{$field}" : $field]);
@endphp

<th scope="col" {{ $attributes }} @if ($active) aria-sort="{{ $descending ? 'descending' : 'ascending' }}" @endif>
    <a href="{{ request()->url().'?'.http_build_query($query) }}" class="inline-flex items-center gap-1 hover:text-fg">
        {{ $label }}<span aria-hidden="true">{{ $active ? ($descending ? '↓' : '↑') : '' }}</span>
    </a>
</th>
