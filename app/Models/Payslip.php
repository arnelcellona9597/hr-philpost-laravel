<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Support\Names;
use Database\Factories\PayslipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['period_id', 'employee_id', 'full_name', 'employee_code', 'position', 'station', 'classification', 'tin', 'sss', 'philhealth', 'pagibig', 'earnings', 'deductions', 'remarks'])]
class Payslip extends Model
{
    /** @use HasFactory<PayslipFactory> */
    use Audited, HasFactory;

    /**
     * Totals are added up in whole centavos: no floating-point drift and no tax formulas.
     */
    protected static function booted(): void
    {
        static::saving(function (Payslip $payslip): void {
            $gross = array_sum(array_column($payslip->earnings ?? [], 'amount_centavos'));
            $deductions = array_sum(array_column($payslip->deductions ?? [], 'amount_centavos'));

            $payslip->gross_pay_centavos = $gross;
            $payslip->total_deductions_centavos = $deductions;
            $payslip->net_pay_centavos = $gross - $deductions;
        });
    }

    /**
     * The employee's name in title case for display; the saved snapshot is unchanged.
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (?string $value): string => Names::title($value));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'earnings' => 'array',
            'deductions' => 'array',
            'gross_pay_centavos' => 'integer',
            'total_deductions_centavos' => 'integer',
            'net_pay_centavos' => 'integer',
            'corrected_after_release' => 'boolean',
        ];
    }

    /**
     * The employee details printed on this payslip under the template's settings.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function detailRows(PayslipSetting $settings): array
    {
        $shown = $settings->shown_fields;
        $rows = [['Name', $this->full_name]];

        $optional = [
            'employee_id' => ['Employee ID', $this->employee_code],
            'position' => ['Position', $this->position],
            'station' => ['Station', $this->station],
            'classification' => ['Classification', $this->classification],
            'tin' => ['TIN', $this->tin],
            'sss' => ['SSS', $this->sss],
            'philhealth' => ['PhilHealth', $this->philhealth],
            'pagibig' => ['Pag-IBIG', $this->pagibig],
        ];

        foreach ($optional as $field => [$label, $value]) {
            if ($shown[$field] ?? false) {
                $rows[] = [$label, (string) $value];
            }
        }

        return $rows;
    }

    /**
     * @return BelongsTo<PayrollPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'period_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
