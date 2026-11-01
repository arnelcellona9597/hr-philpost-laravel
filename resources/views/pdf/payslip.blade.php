{{-- Payslip PDF. DejaVu Sans carries the peso sign, and tables stand in for flexbox. --}}
@php
    use App\Support\HrDate;
    use App\Support\Money;

    $symbol = $settings->currency_symbol ?: '₱';
    $size = $half ? 8.5 : 10;
    $margin = $half ? 28 : 44;
    $lines = array_slice(array_values(array_filter(array_map(trim(...), explode("\n", (string) $settings->address_lines)))), 0, 4);
    $details = array_chunk($slip->detailRows($settings), 2);
    $signatures = array_values(array_filter([
        $settings->prepared_by_name ? ['Prepared by', $settings->prepared_by_name, $settings->prepared_by_title] : null,
        $settings->certified_by_name ? ['Certified correct', $settings->certified_by_name, $settings->certified_by_title] : null,
    ]));
    $sections = [
        ['Earnings', $slip->earnings, 'Gross pay', $slip->gross_pay_centavos],
        ['Deductions', $slip->deductions, 'Total deductions', $slip->total_deductions_centavos],
    ];
@endphp
<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <title>{{ $settings->title ?: 'Payslip' }} — {{ $slip->full_name }} — {{ $period->name }}</title>
        <style>
            @page { margin: {{ $margin }}pt; }
            body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: {{ $size }}pt; color: #121419; line-height: 1.3; }
            table { width: 100%; border-collapse: collapse; }
            td, th { padding: 0; vertical-align: top; text-align: left; }
            .muted { color: #6b7280; }
            .small { font-size: {{ $size - 1 }}pt; }
            .right { text-align: right; }
            .bold { font-weight: bold; }
            .header { border-bottom: 1px solid #d9dbe0; }
            .header td { padding-bottom: 8pt; vertical-align: middle; }
            .logo-cell { width: 1%; padding-right: 10pt; }
            .company { font-size: {{ $size + 4 }}pt; font-weight: bold; }
            .title { font-size: {{ $size + 3 }}pt; font-weight: bold; }
            .heading { margin-top: 8pt; }
            .details { margin-top: 12pt; }
            .details td { padding-bottom: 4pt; }
            .label-cell { width: {{ $half ? 62 : 90 }}pt; }
            .items { margin-top: 12pt; }
            .items th { background: #f2f4f7; padding: 3pt 6pt; font-weight: bold; }
            .items td { padding: 2pt 6pt; }
            .items .total td { border-top: 1px solid #d9dbe0; font-weight: bold; padding-top: 3pt; }
            .net { margin-top: 12pt; background: #ebf2ff; border: 1px solid #b3c7eb; }
            .net td { padding: 6pt 8pt; font-weight: bold; }
            .net .amount { font-size: {{ $size + 3 }}pt; }
            .remarks { margin-top: 10pt; }
            .signatures { margin-top: 30pt; }
            .signature { width: 50%; padding-right: 24pt; }
            .signature-line { border-top: 1px solid #6b7280; margin-top: 24pt; padding-top: 2pt; font-weight: bold; }
            .footer { margin-top: 24pt; font-size: {{ $size - 1.5 }}pt; color: #6b7280; }
            .generated { margin-top: 2pt; font-size: {{ $size - 2 }}pt; color: #6b7280; text-align: right; }
        </style>
    </head>
    <body>
        <table class="header">
            <tr>
                @if ($logo)
                    <td class="logo-cell"><img src="{{ $logo['src'] }}" alt="" style="width:{{ $logo['size'][0] }}pt;height:{{ $logo['size'][1] }}pt" /></td>
                @endif
                <td>
                    <div class="company">{{ $companyName }}</div>
                    @foreach ($lines as $line)
                        <div class="small muted">{{ $line }}</div>
                    @endforeach
                </td>
            </tr>
        </table>

        <table class="heading">
            <tr><td class="title">{{ $settings->title ?: 'PAYSLIP' }}</td><td class="right bold">{{ $period->name }}</td></tr>
            <tr>
                <td class="small muted">Period: {{ HrDate::format($period->period_start) }} – {{ HrDate::format($period->period_end) }}</td>
                <td class="small muted right">Pay date: {{ HrDate::format($period->pay_date) }}</td>
            </tr>
        </table>

        <table class="details">
            @foreach ($details as $row)
                <tr>
                    @foreach ($row as [$label, $value])
                        <td class="label-cell small muted">{{ $label }}</td>
                        <td>{{ $value ?: '—' }}</td>
                    @endforeach
                    @if (count($row) === 1)
                        <td class="label-cell"></td><td></td>
                    @endif
                </tr>
            @endforeach
        </table>

        @foreach ($sections as [$title, $items, $totalLabel, $total])
            <table class="items">
                <tr><th>{{ $title }}</th><th class="right">Amount</th></tr>
                @foreach ($items ?? [] as $item)
                    @if ($item['amount_centavos'])
                        <tr><td>{{ $item['label'] }}</td><td class="right">{{ Money::format($item['amount_centavos'], $symbol) }}</td></tr>
                    @endif
                @endforeach
                <tr class="total"><td>{{ $totalLabel }}</td><td class="right">{{ Money::format($total, $symbol) }}</td></tr>
            </table>
        @endforeach

        <table class="net"><tr><td>NET PAY</td><td class="right amount">{{ Money::format($slip->net_pay_centavos, $symbol) }}</td></tr></table>

        @if ($slip->remarks)
            <p class="remarks small muted">Remarks: {{ $slip->remarks }}</p>
        @endif

        @if ($signatures !== [])
            <table class="signatures">
                <tr>
                    @foreach ($signatures as [$label, $name, $title])
                        <td class="signature">
                            <div class="small muted">{{ $label }}</div>
                            <div class="signature-line">{{ $name }}</div>
                            @if ($title)
                                <div class="small muted">{{ $title }}</div>
                            @endif
                        </td>
                    @endforeach
                    @if (count($signatures) === 1)
                        <td class="signature"></td>
                    @endif
                </tr>
            </table>
        @endif

        @if (trim((string) $settings->footer_note) !== '')
            <div class="footer">{{ $settings->footer_note }}</div>
        @endif
        <div class="generated">Generated {{ now('Asia/Manila')->format('M j, Y g:i A') }}</div>
    </body>
</html>
