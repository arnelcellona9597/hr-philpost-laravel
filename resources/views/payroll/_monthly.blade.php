{{-- The monthly COS payroll summary, shared by the page and the PDF: tables and inline styles only. --}}
@php
    $amount = fn (int $centavos): string => $centavos === 0 ? '' : number_format($centavos / 100, 2);
    $days = fn ($value): string => (float) $value == 0.0 ? '' : rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $cell = 'border:0.5pt solid #555;padding:1.5pt 2pt;';
    $num = $cell.'text-align:right;white-space:nowrap;';
    $head = $cell.'background:#e8eef8;font-weight:bold;text-align:center;vertical-align:middle;';
    $parts = ['days' => 'Days', 'gross' => 'Gross', 'late' => 'Late', 'overtime' => 'Overtime', 'deductions' => 'Deductions', 'net' => 'Net Pay'];
    $groupCells = function (?array $half) use ($amount, $days, $num, $parts): string {
        $html = '';
        foreach (array_keys($parts) as $key) {
            $value = $half === null ? '' : ($key === 'days' ? $days($half[$key]) : $amount((int) $half[$key]));
            $html .= '<td style="'.$num.($key === 'net' ? 'font-weight:bold;' : '').'">'.e($value).'</td>';
        }

        return $html;
    };
    $periodLabel = fn (int $half): string => $half === 1 ? '1st half (1–15)' : '2nd half (16–end)';
@endphp

<div style="font-family:Helvetica,Arial,'DejaVu Sans',sans-serif;color:#111;font-size:7pt">
    {!! $letterhead !!}

    <div style="text-align:center;margin:8pt 0 6pt">
        <div style="font-size:11pt;font-weight:bold">MONTHLY PAYROLL SUMMARY OF CONTRACT OF SERVICE (COS)</div>
        <div style="font-size:9pt;font-weight:bold">{{ $summary['title'] }}</div>
    </div>

    <table style="width:100%;border-collapse:collapse;margin-bottom:6pt;font-size:7.5pt">
        <tr>
            @foreach ($summary['periods'] as $half => $period)
                <td style="padding:2pt 6pt 2pt 0">
                    <strong>{{ $periodLabel($half) }}:</strong>
                    @if ($period)
                        {{ $period->titleRange() }} · {{ $period->status->value }}{{ $period->batch ? ' · Batch '.$period->batch : '' }}
                    @else
                        no payroll yet
                    @endif
                </td>
            @endforeach
        </tr>
    </table>

    <table style="width:100%;border-collapse:collapse">
        <thead style="display:table-header-group">
            <tr>
                <th rowspan="2" style="{{ $head }}">No.</th>
                <th rowspan="2" style="{{ $head }}">Name</th>
                <th rowspan="2" style="{{ $head }}">Official Station</th>
                <th colspan="6" style="{{ $head }}">{{ $periodLabel(1) }}</th>
                <th colspan="6" style="{{ $head }}">{{ $periodLabel(2) }}</th>
                <th colspan="6" style="{{ $head }}">Month total</th>
            </tr>
            <tr>
                @foreach ([1, 2, 3] as $group)
                    @foreach ($parts as $label)
                        <th style="{{ $head }}">{{ $label }}</th>
                    @endforeach
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($summary['rows'] as $index => $row)
                <tr style="page-break-inside:avoid">
                    <td style="{{ $num }}">{{ $index + 1 }}</td>
                    <td style="{{ $cell }}white-space:nowrap">{{ mb_strtoupper($row['name']) }}</td>
                    <td style="{{ $cell }}">{{ $row['station'] }}</td>
                    {!! $groupCells($row['halves'][1]) !!}
                    {!! $groupCells($row['halves'][2]) !!}
                    {!! $groupCells($row['total']) !!}
                </tr>
            @empty
                <tr><td colspan="21" style="{{ $cell }}text-align:center;padding:8pt">No payroll for this month yet.</td></tr>
            @endforelse
            <tr style="font-weight:bold;background:#f3f4f6">
                <td colspan="3" style="{{ $cell }}text-align:right">TOTAL ({{ count($summary['rows']) }} workers)</td>
                {!! $groupCells($summary['totals'][1]) !!}
                {!! $groupCells($summary['totals'][2]) !!}
                {!! $groupCells($summary['totals']['month']) !!}
            </tr>
        </tbody>
    </table>
</div>
