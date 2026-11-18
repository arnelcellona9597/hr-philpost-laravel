<x-layouts.print :title="'Payroll sheet — '.$period->name" paper="Legal landscape" margin="8mm" :back="route('payroll.show', $period)">
    <article class="sheet mx-auto mb-6 max-w-[13.5in] rounded-lg border border-line bg-white p-6 shadow-sm">
        @include('payroll._register')
    </article>
</x-layouts.print>
