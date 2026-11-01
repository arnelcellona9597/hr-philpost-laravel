<x-layouts.print :title="'Voucher — '.$remittance->label" paper="A4" :back="route('remittances.show', $remittance)">
    <article class="sheet mx-auto max-w-[8.27in] bg-white p-6 text-[#111418]">
        @include('remittances._voucher')
    </article>
</x-layouts.print>
