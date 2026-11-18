<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Support\CosPay;
use App\Support\Money;
use App\Support\Names;
use Database\Factories\PayslipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['period_id', 'employee_id', 'full_name', 'employee_code', 'position', 'station', 'classification', 'tin', 'sss', 'philhealth', 'pagibig', 'earnings', 'deductions', 'remarks', 'batch_label', 'specific_station', 'period_covered', 'daily_rate_centavos', 'premium_percent', 'days_worked', 'late_minutes', 'ot_hours', 'ot_rest_hours', 'sss_centavos', 'hdmf_centavos', 'cmpeai_centavos', 'absences', 'tardiness', 'rate_source', 'wage_order_id', 'region', 'regional_minimum_centavos'])]
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
            'daily_rate_centavos' => 'integer',
            'premium_percent' => 'decimal:2',
            'days_worked' => 'decimal:2',
            'late_minutes' => 'integer',
            'ot_hours' => 'decimal:2',
            'ot_rest_hours' => 'decimal:2',
            'sss_centavos' => 'integer',
            'hdmf_centavos' => 'integer',
            'cmpeai_centavos' => 'integer',
            'absences' => 'decimal:2',
            'tardiness' => 'integer',
            'wage_order_id' => 'integer',
            'regional_minimum_centavos' => 'integer',
            'corrected_after_release' => 'boolean',
        ];
    }

    /**
     * Whether the daily rate is below the regional minimum that was in force for the period.
     */
    public function isBelowMinimum(): bool
    {
        return $this->regional_minimum_centavos !== null && (int) $this->daily_rate_centavos > 0 && (int) $this->daily_rate_centavos < (int) $this->regional_minimum_centavos;
    }

    /**
     * Where the daily rate came from, for people: the worker's contract rate or the regional minimum.
     */
    public function rateSourceLabel(): string
    {
        return match ($this->rate_source) {
            'regional' => 'Regional minimum',
            'contract' => 'Contract rate',
            default => 'No rate',
        };
    }

    /**
     * @return BelongsTo<WageOrder, $this>
     */
    public function wageOrder(): BelongsTo
    {
        return $this->belongsTo(WageOrder::class);
    }

    /**
     * Every computed COS column for this payslip.
     *
     * @return array{premium: int, total_daily_rate: int, gross: int, rate_per_minute: int, late: int, hourly_rate: int, hourly_rest_rate: int, overtime: int, gross_after_late: int, deductions: int, net: int, excess: int}
     */
    public function cosPay(): array
    {
        return CosPay::compute([
            'daily_rate_centavos' => (int) $this->daily_rate_centavos,
            'premium_percent' => (string) $this->premium_percent,
            'days_worked' => (string) $this->days_worked,
            'late_minutes' => (int) $this->late_minutes,
            'ot_hours' => (string) $this->ot_hours,
            'ot_rest_hours' => (string) $this->ot_rest_hours,
            'sss_centavos' => (int) $this->sss_centavos,
            'hdmf_centavos' => (int) $this->hdmf_centavos,
            'cmpeai_centavos' => (int) $this->cmpeai_centavos,
            'absences' => (string) $this->absences,
        ]);
    }

    /**
     * The attendance behind the pay, printed above the earnings: days at the total daily rate, lates
     * and overtime. Empty when the payslip has no daily rate.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function attendanceLines(string $symbol = '₱'): array
    {
        if (! $this->daily_rate_centavos) {
            return [];
        }

        $pay = $this->cosPay();
        $number = fn ($value): string => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
        $basis = $this->rate_source === 'regional' ? 'Regional minimum'.($this->region ? ' ('.$this->region.')' : '') : 'Contract rate';
        $lines = [
            ['Rate basis', $basis],
            ['Total daily rate', Money::format((int) $this->daily_rate_centavos, $symbol).' + '.$number($this->premium_percent).'% premium = '.Money::format($pay['total_daily_rate'], $symbol)],
            ['Days worked', $number($this->days_worked).' day(s)'.($this->period_covered ? ' ('.$this->period_covered.')' : '')],
        ];

        if ($this->late_minutes) {
            $lines[] = ['Late', $this->late_minutes.' min × '.Money::format($pay['rate_per_minute'], $symbol)];
        }

        if ((float) $this->ot_hours || (float) $this->ot_rest_hours) {
            $lines[] = ['Overtime', trim(((float) $this->ot_hours ? $number($this->ot_hours).' hr regular' : '').((float) $this->ot_hours && (float) $this->ot_rest_hours ? '; ' : '').((float) $this->ot_rest_hours ? $number($this->ot_rest_hours).' hr rest day / special holiday' : ''))];
        }

        return $lines;
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
