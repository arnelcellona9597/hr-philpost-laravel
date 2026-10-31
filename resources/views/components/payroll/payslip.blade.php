{{-- HTML payslip for the print pages and the template preview. Mirrors the PDF layout. --}}
@props(['settings', 'companyName', 'logoUrl' => null, 'period', 'slip'])

@php
    use App\Support\HrDate;
    use App\Support\Money;

    $symbol = $settings->currency_symbol ?: '₱';
    $lines = array_values(array_filter(array_map(trim(...), explode("\n", (string) $settings->address_lines))));
    $signatures = array_filter([
        $settings->prepared_by_name ? ['Prepared by', $settings->prepared_by_name, $settings->prepared_by_title] : null,
        $settings->certified_by_name ? ['Certified correct', $settings->certified_by_name, $settings->certified_by_title] : null,
    ]);
    $tables = [
        ['Earnings', $slip->earnings, 'Gross pay', $slip->gross_pay_centavos],
        ['Deductions', $slip->deductions, 'Total deductions', $slip->total_deductions_centavos],
    ];
@endphp

<article @class([
    'sheet mx-auto mb-6 rounded-lg border border-line bg-white p-8 text-[13px] text-[#111418] shadow-sm',
    $settings->paper_size === 'Half-Letter' ? 'max-w-[5.5in]' : 'max-w-[8.27in]',
])>
    <header class="flex items-center gap-3 border-b border-[#dfe1e5] pb-3">
        @if ($settings->show_logo && $logoUrl)
            <img src="{{ $logoUrl }}" alt="" class="h-12 w-auto object-contain" />
        @endif
        <div>
            <p class="text-lg font-bold">{{ $companyName }}</p>
            @foreach ($lines as $line)
                <p class="text-xs text-[#6b7280]">{{ $line }}</p>
            @endforeach
        </div>
    </header>
    <div class="mt-3 flex items-baseline justify-between gap-3">
        <h2 class="text-base font-bold tracking-wide">{{ $settings->title ?: 'PAYSLIP' }}</h2>
        <p class="font-semibold">{{ $period->name }}</p>
    </div>
    <div class="flex justify-between text-xs text-[#6b7280]">
        <span>Period: {{ HrDate::format($period->period_start) }} – {{ HrDate::format($period->period_end) }}</span>
        <span>Pay date: {{ HrDate::format($period->pay_date) }}</span>
    </div>
    <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-1.5">
        @foreach ($slip->detailRows($settings) as [$label, $value])
            <div class="flex gap-2"><dt class="w-28 shrink-0 text-xs text-[#6b7280]">{{ $label }}</dt><dd class="font-medium">{{ $value ?: '—' }}</dd></div>
        @endforeach
    </dl>
    @foreach ($tables as [$title, $items, $totalLabel, $total])
        <table class="mt-5 w-full">
            <thead><tr class="bg-[#f2f4f7]"><th class="px-2 py-1.5 text-left">{{ $title }}</th><th class="px-2 py-1.5 text-right">Amount</th></tr></thead>
            <tbody>
                @foreach ($items ?? [] as $item)
                    @if ($item['amount_centavos'])
                        <tr><td class="px-2 py-1">{{ $item['label'] }}</td><td class="px-2 py-1 text-right tabular-nums">{{ Money::format($item['amount_centavos'], $symbol) }}</td></tr>
                    @endif
                @endforeach
                <tr class="border-t border-[#dfe1e5] font-semibold"><td class="px-2 py-1.5">{{ $totalLabel }}</td><td class="px-2 py-1.5 text-right tabular-nums">{{ Money::format($total, $symbol) }}</td></tr>
            </tbody>
        </table>
    @endforeach
    <div class="mt-5 flex items-center justify-between rounded-md border border-[#b3c7eb] bg-[#ebf2ff] px-3 py-2.5">
        <span class="font-bold">NET PAY</span><span class="text-lg font-bold tabular-nums">{{ Money::format($slip->net_pay_centavos, $symbol) }}</span>
    </div>
    @if ($slip->remarks)
        <p class="mt-3 text-xs text-[#6b7280]">Remarks: {{ $slip->remarks }}</p>
    @endif
    @if ($signatures)
        <div class="mt-10 grid grid-cols-2 gap-8">
            @foreach ($signatures as [$label, $name, $title])
                <div>
                    <p class="text-xs text-[#6b7280]">{{ $label }}</p>
                    <div class="mt-6 border-t border-[#6b7280] pt-1 font-semibold">{{ $name }}</div>
                    @if ($title)
                        <p class="text-xs text-[#6b7280]">{{ $title }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
    @if ($settings->footer_note)
        <p class="mt-8 text-[11px] text-[#6b7280]">{{ $settings->footer_note }}</p>
    @endif
</article>
