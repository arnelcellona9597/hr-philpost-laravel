<x-layouts.print :title="'Employee profiles — '.number_format($profiles->count()).' printed'" paper="A4" :back="$back">
    @if ($total > $max)
        <div class="no-print mx-auto mb-4 max-w-[8.27in] rounded-lg bg-warning-soft px-4 py-3 text-sm text-warning">
            Showing the first {{ number_format($max) }} of {{ number_format($total) }} matching employees. Narrow the filters, or print the rest in another batch, to get the others.
        </div>
    @endif

    @foreach ($profiles as $profile)
        <x-employees.profile
            :employee="$profile['employee']"
            :fields="$fields"
            :employment="$profile['employment']"
            :pay="$profile['pay']"
            :position-changes="$profile['positionChanges']"
            :site="$site"
        />
    @endforeach
</x-layouts.print>
