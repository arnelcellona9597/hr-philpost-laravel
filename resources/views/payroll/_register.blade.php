{{-- The COS payroll sheet, shared by the print page and the PDF: tables and inline styles only, because the PDF engine has no flexbox. --}}
@php
    $amount = fn (int $centavos): string => $centavos === 0 ? '' : number_format($centavos / 100, 2);
    $quantity = fn ($value): string => (float) $value == 0.0 ? '' : rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $cell = 'border:0.5pt solid #555;padding:1.5pt 2pt;';
    $num = $cell.'text-align:right;white-space:nowrap;';
    $head = $cell.'background:#e8eef8;font-weight:bold;text-align:center;vertical-align:middle;';
    $columns = ['No.', 'Name', 'Official Station', 'Daily Rate', 'Premium', 'Total Daily Rate', 'Date Period Covered', 'No. of Days', 'Gross', 'Late (mins)', 'Total Late', 'OT Hours', 'OT Hours RD/SH', 'Overtime Pay', 'Gross after Late', 'SSS', 'HDMF', 'CMPEAI', 'Net Pay', 'Signature'];
    $totalRow = function (string $label, array $totals) use ($amount, $quantity, $cell, $num): string {
        return '<tr style="font-weight:bold;background:#f3f4f6"><td colspan="7" style="'.$cell.'text-align:right">'.e($label).'</td>'
            .'<td style="'.$num.'">'.$quantity($totals['days']).'</td>'
            .'<td style="'.$num.'">'.$amount($totals['gross']).'</td><td style="'.$cell.'"></td>'
            .'<td style="'.$num.'">'.$amount($totals['late']).'</td><td style="'.$cell.'"></td><td style="'.$cell.'"></td>'
            .'<td style="'.$num.'">'.$amount($totals['overtime']).'</td>'
            .'<td style="'.$num.'">'.$amount($totals['after']).'</td>'
            .'<td style="'.$num.'">'.$amount($totals['sss']).'</td>'
            .'<td style="'.$num.'">'.$amount($totals['hdmf']).'</td>'
            .'<td style="'.$num.'">'.$amount($totals['cmpeai']).'</td>'
            .'<td style="'.$num.'">'.$amount($totals['net']).'</td><td style="'.$cell.'"></td></tr>';
    };
@endphp

<div style="font-family:Helvetica,Arial,'DejaVu Sans',sans-serif;color:#111;font-size:7pt">
    {!! $letterhead !!}

    <div style="text-align:center;margin:8pt 0 6pt">
        <div style="font-size:11pt;font-weight:bold">PAYROLL OF CONTRACT OF SERVICE (COS)</div>
        <div style="font-size:9pt;font-weight:bold">{{ $period->titleRange() }}</div>
        @if ($period->batch)
            <div style="font-size:9pt;font-weight:bold">BATCH {{ $period->batch }}</div>
        @endif
    </div>

    <table style="width:100%;border-collapse:collapse">
        <thead style="display:table-header-group">
            <tr>
                @foreach ($columns as $column)
                    <th style="{{ $head }}">{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($register['groups'] as $group)
                @foreach ($group['rows'] as $row)
                    @php($slip = $row['slip'])
                    <tr style="page-break-inside:avoid">
                        <td style="{{ $num }}">{{ $row['number'] }}</td>
                        <td style="{{ $cell }}white-space:nowrap">{{ mb_strtoupper($slip->full_name) }}</td>
                        <td style="{{ $cell }}">{{ $slip->station }}</td>
                        <td style="{{ $num }}">{{ $amount((int) $slip->daily_rate_centavos) }}</td>
                        <td style="{{ $num }}">{{ $amount($row['pay']['premium']) }}</td>
                        <td style="{{ $num }}">{{ $amount($row['pay']['total_daily_rate']) }}</td>
                        <td style="{{ $cell }}text-align:center;white-space:nowrap">{{ $slip->period_covered }}</td>
                        <td style="{{ $num }}">{{ $quantity($slip->days_worked) }}</td>
                        <td style="{{ $num }}">{{ $amount($row['pay']['gross']) }}</td>
                        <td style="{{ $num }}">{{ $slip->late_minutes ?: '' }}</td>
                        <td style="{{ $num }}">{{ $amount($row['pay']['late']) }}</td>
                        <td style="{{ $num }}">{{ $quantity($slip->ot_hours) }}</td>
                        <td style="{{ $num }}">{{ $quantity($slip->ot_rest_hours) }}</td>
                        <td style="{{ $num }}">{{ $amount($row['pay']['overtime']) }}</td>
                        <td style="{{ $num }}">{{ $amount($row['pay']['gross_after_late']) }}</td>
                        <td style="{{ $num }}">{{ $amount((int) $slip->sss_centavos) }}</td>
                        <td style="{{ $num }}">{{ $amount((int) $slip->hdmf_centavos) }}</td>
                        <td style="{{ $num }}">{{ $amount((int) $slip->cmpeai_centavos) }}</td>
                        <td style="{{ $num }}font-weight:bold">{{ number_format($row['pay']['net'] / 100, 2) }}</td>
                        <td style="{{ $cell }}width:60pt"></td>
                    </tr>
                @endforeach
                @if (count($register['groups']) > 1)
                    {!! $totalRow('Subtotal — '.$group['station'], $group['totals']) !!}
                @endif
            @endforeach
            {!! $totalRow('GRAND TOTAL ('.$register['count'].' workers)', $register['totals']) !!}
        </tbody>
    </table>

    @if ($register['signatories'] !== [])
        <table style="width:100%;border-collapse:collapse;margin-top:22pt;page-break-inside:avoid">
            <tr>
                @foreach ($register['signatories'] as [$label, $name, $title])
                    <td style="vertical-align:top;padding-right:30pt;font-size:8pt">
                        <div>{{ $label }}</div>
                        <div style="margin-top:22pt;font-weight:bold;text-transform:uppercase">{{ $name }}</div>
                        <div>{{ $title }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif
</div>
