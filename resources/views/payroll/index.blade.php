@php
    use App\Support\HrDate;
    use App\Support\Money;
@endphp

<x-layouts.app title="Payroll" heading="Payroll" description="Payroll records and payslips. Amounts are entered by HR; the system adds up the totals, prints payslips and emails them as PDFs.">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('payroll.settings') }}"><x-icon name="settings" /> Payslip template</a>
        <a class="btn-primary" href="{{ route('payroll.create') }}"><x-icon name="plus" /> New payroll period</a>
    </x-slot:actions>

    <div class="card overflow-hidden">
        @if ($periods->isEmpty())
            <x-empty-state title="No payroll periods yet" text="Create a period (e.g. 'October 2026 — 1st half'), then generate payslips for your active employees.">
                <a class="btn-primary" href="{{ route('payroll.create') }}">New payroll period</a>
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th>Period</th><th>Code</th><th>Dates</th><th>Pay date</th><th class="text-right">Payslips</th><th class="text-right">Gross</th><th class="text-right">Net</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($periods as $period)
                            <tr>
                                <td class="font-medium"><a class="hover:text-primary-ink" href="{{ route('payroll.show', $period) }}">{{ $period->name }}</a></td>
                                <td class="font-mono text-xs text-fg-2">{{ $period->code }}</td>
                                <td class="whitespace-nowrap tabular text-fg-2">{{ HrDate::format($period->period_start) }} – {{ HrDate::format($period->period_end) }}</td>
                                <td class="whitespace-nowrap tabular">{{ HrDate::format($period->pay_date) }}</td>
                                <td class="text-right tabular">{{ $period->payslips_count }}</td>
                                <td class="text-right tabular">{{ Money::format((int) $period->gross_total) }}</td>
                                <td class="text-right font-medium tabular">{{ Money::format((int) $period->net_total) }}</td>
                                <td>
                                    <span class="{{ $period->isReleased() ? 'badge-success' : 'badge-warning' }}">{{ $period->status->value }}</span>
                                    @if ($period->corrected_count > 0)
                                        <span class="badge-danger ml-1" title="Payslips changed after release">{{ $period->corrected_count }} corrected</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-layouts.app>
