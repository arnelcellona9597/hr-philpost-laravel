<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\IsSingleton;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'company_name', 'address_lines', 'show_logo', 'currency_symbol', 'earning_items', 'deduction_items', 'shown_fields', 'prepared_by_name', 'prepared_by_title', 'certified_by_name', 'certified_by_title', 'footer_note', 'paper_size'])]
class PayslipSetting extends Model
{
    use Audited, IsSingleton;

    /**
     * Values for the row created on a fresh install.
     *
     * @return array<string, mixed>
     */
    protected static function defaultValues(): array
    {
        return [
            'earning_items' => ['Basic Pay', 'PERA', 'Overtime', 'Allowances'],
            'deduction_items' => ['Withholding Tax', 'GSIS / SSS', 'PhilHealth', 'Pag-IBIG', 'Loans'],
            'shown_fields' => [
                'employee_id' => true,
                'position' => true,
                'station' => true,
                'classification' => true,
                'tin' => false,
                'sss' => false,
                'philhealth' => false,
                'pagibig' => false,
            ],
            'prepared_by_title' => 'HR Officer',
            'certified_by_title' => 'Head of Office',
            'footer_note' => 'This is a system-generated payslip. Please report any discrepancy to the HR office.',
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_logo' => 'boolean',
            'earning_items' => 'array',
            'deduction_items' => 'array',
            'shown_fields' => 'array',
        ];
    }
}
