@php
    use App\Support\Badge;

    $list = route('requirements.index', [$type, 'year' => $record->year]);
@endphp

<x-layouts.app :title="$module['title']" :heading="$module['title'].': '.$record->first_name.' '.$record->last_name.' ('.$record->year.')'" :back="['href' => $list, 'label' => $module['title']]">
    <x-slot:actions>
        <span class="{{ Badge::TIMELINESS[$timely['status']] }}" title="Date Submitted compared with the deadline" data-timeliness>{{ $timely['label'] }}</span>
        @if ($record->adjectival_rating)
            <span class="badge-primary">{{ $record->adjectival_rating }}</span>
        @endif
        <form method="post" action="{{ route('requirements.destroy', [$type, $record->id]) }}" data-confirm="Delete this record?">
            @csrf
            @method('DELETE')
            <button class="btn-danger"><x-icon name="trash" /> Delete</button>
        </form>
    </x-slot:actions>
    <div class="max-w-5xl">
        <x-module-form
            :fields="$fields"
            :values="$values"
            :choices="['employee' => $employees]"
            :cancel-href="$list"
            submit-label="Save changes"
            spoof="PUT"
            :action="route('requirements.update', [$type, $record->id])"
        />
    </div>
</x-layouts.app>
