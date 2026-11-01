{{-- Disbursement voucher body. Tables and inline styles only, so the browser and the PDF engine draw it the same. --}}
@php
    use App\Enums\RemittanceKind;
    use App\Support\HrDate;
    use App\Support\Money;

    $kind = $remittance->kind;
    $letterhead = trim((string) $setting->letterhead_lines) !== '' ? trim((string) $setting->letterhead_lines) : mb_strtoupper($site->company_name);
    $box = 'border:1px solid #333; padding:4pt 6pt; vertical-align:top;';
    $peso = fn (int $centavos): string => 'PHP '.number_format($centavos / 100, 2);
@endphp
<table style="width:100%; border-collapse:collapse; font-size:9pt;">
    <tr>
        <td style="{{ $box }} width:70%; text-align:center;">
            @isset($logo)
                <img src="{{ $logo['src'] }}" style="width:{{ $logo['size'][0] }}pt; height:{{ $logo['size'][1] }}pt;" alt="" />
            @endisset
            <div>Republic of the Philippines</div>
            <div style="font-weight:bold; white-space:pre-line;">{{ $letterhead }}</div>
            <div style="font-size:13pt; font-weight:bold; margin-top:4pt;">DISBURSEMENT VOUCHER</div>
        </td>
        <td style="{{ $box }}">
            <div>Fund Cluster: <strong>{{ $officials->fund_cluster }}</strong></div>
            <div style="margin-top:6pt;">Date: <strong>{{ HrDate::format($remittance->voucher_date) }}</strong></div>
            <div style="margin-top:6pt;">DV No.: <strong>{{ $remittance->voucher_number }}</strong></div>
        </td>
    </tr>
</table>
<table style="width:100%; border-collapse:collapse; font-size:9pt; margin-top:-1px;">
    <tr>
        <td style="{{ $box }} width:18%;">Mode of Payment</td>
        <td style="{{ $box }}"><strong>{{ $mode }}</strong></td>
    </tr>
    <tr>
        <td style="{{ $box }}">Payee</td>
        <td style="{{ $box }}"><strong>{{ $setting->voucher_payee }}</strong></td>
    </tr>
    <tr>
        <td style="{{ $box }}">Address</td>
        <td style="{{ $box }}">{{ $setting->voucher_address }}</td>
    </tr>
</table>
<table style="width:100%; border-collapse:collapse; font-size:9pt; margin-top:-1px;">
    <tr style="text-align:center; font-weight:bold;">
        <td style="{{ $box }}">Particulars</td>
        <td style="{{ $box }} width:20%;">Responsibility Center</td>
        <td style="{{ $box }} width:20%;">Amount</td>
    </tr>
    <tr>
        <td style="{{ $box }} height:110pt;">
            {{ $particulars }}
            @if ($kind === RemittanceKind::Cmpeai)
                <table style="width:70%; border-collapse:collapse; margin-top:10pt;">
                    <tr><td>Gross amount</td><td style="text-align:right;">{{ $peso($totals['gross']) }}</td></tr>
                    <tr><td>Less: service charge ({{ rtrim(rtrim(number_format($setting->service_charge_basis_points / 100, 2), '0'), '.') }}%)</td><td style="text-align:right;">({{ $peso($totals['serviceCharge']) }})</td></tr>
                    <tr><td><strong>Net amount</strong></td><td style="text-align:right;"><strong>{{ $peso($totals['net']) }}</strong></td></tr>
                </table>
            @elseif ($kind === RemittanceKind::Hdmf && $totals['employer'] > 0)
                <table style="width:70%; border-collapse:collapse; margin-top:10pt;">
                    <tr><td>Employee share</td><td style="text-align:right;">{{ $peso($totals['gross']) }}</td></tr>
                    <tr><td>Employer share</td><td style="text-align:right;">{{ $peso($totals['employer']) }}</td></tr>
                </table>
            @endif
        </td>
        <td style="{{ $box }}">{{ $officials->responsibility_center }}</td>
        <td style="{{ $box }} text-align:right;"><strong>{{ $peso($totals['payable']) }}</strong></td>
    </tr>
    <tr>
        <td colspan="3" style="{{ $box }}">Amount in words: <strong>{{ $words }}</strong></td>
    </tr>
</table>
<table style="width:100%; border-collapse:collapse; font-size:9pt; margin-top:-1px;">
    <tr>
        <td style="{{ $box }} width:50%; height:90pt;">
            <strong>A. Certified:</strong> Expenses/cash advance necessary, lawful and incurred under my direct supervision.
            <div style="margin-top:28pt; text-align:center; font-weight:bold;">{{ $officials->supervisor_name ? mb_strtoupper($officials->supervisor_name) : '' }}</div>
            <div style="text-align:center;">{{ $officials->supervisor_title }}</div>
        </td>
        <td style="{{ $box }} height:90pt;">
            <strong>B. Certified:</strong> Supporting documents complete and proper; funds available.
            <div style="margin-top:28pt; text-align:center; font-weight:bold;">{{ $officials->accountant_name ? mb_strtoupper($officials->accountant_name) : '' }}</div>
            <div style="text-align:center;">{{ $officials->accountant_title }}</div>
        </td>
    </tr>
    <tr>
        <td colspan="2" style="{{ $box }} height:80pt;">
            <strong>C. Approved for payment</strong>
            <div style="margin-top:26pt; text-align:center; font-weight:bold;">{{ $officials->approver_name ? mb_strtoupper($officials->approver_name) : '' }}</div>
            <div style="text-align:center;">{{ $officials->approver_title }}</div>
        </td>
    </tr>
</table>
