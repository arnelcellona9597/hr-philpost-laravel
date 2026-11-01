@php
    use App\Support\HrDate;

    $previousDay = null;
@endphp

<x-layouts.print :title="'Received mails — '.$label" paper="A4" :back="route('received-mails.index', ['month' => $month])">
    <article class="sheet mx-auto max-w-[11in] bg-white p-6 text-[12px] text-[#111418]">
        <header class="text-center">
            <p>Republic of the Philippines</p>
            <p class="text-sm font-bold">{{ mb_strtoupper($site->company_name) }}</p>
            @if ($site->tagline)
                <p>{{ $site->tagline }}</p>
            @endif
            <h1 class="mt-5 text-lg font-bold">RECEIVED MAILS</h1>
            <p>FOR THE MONTH OF {{ $label }}</p>
        </header>
        <table class="mt-4 w-full border-collapse">
            <thead>
                <tr class="bg-[#e8eef8]">
                    @foreach (['No.', 'Date', 'Tracking number', 'Post office', 'Description', 'Remarks', 'Received'] as $heading)
                        <th class="border border-[#999] px-2 py-1 text-left">{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($mails as $index => $mail)
                    @php
                        $day = $mail->received_on->toDateString();
                        $newDay = $day !== $previousDay;
                        $previousDay = $day;
                    @endphp
                    <tr class="break-inside-avoid">
                        <td class="border border-[#999] px-2 py-1 text-right">{{ $index + 1 }}</td>
                        <td class="whitespace-nowrap border border-[#999] px-2 py-1">{{ $newDay ? HrDate::format($mail->received_on) : '' }}</td>
                        <td class="whitespace-nowrap border border-[#999] px-2 py-1 font-mono">{{ $mail->tracking_number }}</td>
                        <td class="border border-[#999] px-2 py-1">{{ $mail->post_office }}</td>
                        <td class="border border-[#999] px-2 py-1">{{ $mail->description }}</td>
                        <td class="border border-[#999] px-2 py-1">{{ $mail->remarks }}</td>
                        <td class="border border-[#999] px-2 py-1">{{ $mail->received_by }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="mt-3 font-semibold">Total mails received: {{ $mails->count() }}</p>
    </article>
</x-layouts.print>
