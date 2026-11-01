<x-layouts.print :title="'Payslip — '.$slip->full_name.' — '.$period->name" :paper="$settings->paper_size" :back="route('payroll.payslips.show', $slip)">
    <x-payroll.payslip :settings="$settings" :company-name="$companyName" :logo-url="$logoUrl" :period="$period" :slip="$slip" />
</x-layouts.print>
