<x-layouts.print :title="'Payslips — '.$period->name" :paper="$settings->paper_size" :back="route('payroll.show', $period)">
    @foreach ($slips as $slip)
        <x-payroll.payslip :settings="$settings" :company-name="$companyName" :logo-url="$logoUrl" :period="$period" :slip="$slip" />
    @endforeach
</x-layouts.print>
