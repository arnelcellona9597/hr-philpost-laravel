<?php

namespace App\Models;

use App\Enums\PayrollStatus;
use App\Models\Concerns\Audited;
use Database\Factories\PayrollPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['name', 'code', 'year', 'month', 'half', 'period_start', 'period_end', 'pay_date', 'batch', 'notes'])]
class PayrollPeriod extends Model
{
    /** @use HasFactory<PayrollPeriodFactory> */
    use Audited, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'pay_date' => 'date:Y-m-d',
            'status' => PayrollStatus::class,
            'released_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'year' => 'integer',
            'month' => 'integer',
            'half' => 'integer',
        ];
    }

    /**
     * The period as the payroll title prints it, such as "OCTOBER 16-31, 2026".
     */
    public function titleRange(): string
    {
        $start = $this->period_start;
        $end = $this->period_end;

        if ($start->format('Y-m') === $end->format('Y-m')) {
            return strtoupper($start->format('F')).' '.$start->format('j').'-'.$end->format('j, Y');
        }

        return strtoupper($start->format('F j').' - '.$end->format('F j, Y'));
    }

    /**
     * The "Date Period Covered" column, such as "10/16-31/2026".
     */
    public function coveredLabel(): string
    {
        $start = $this->period_start;
        $end = $this->period_end;

        if ($start->format('Y-m') === $end->format('Y-m')) {
            return $start->format('n/j').'-'.$end->format('j/Y');
        }

        return $start->format('n/j').'-'.$end->format('n/j/Y');
    }

    /**
     * The dates, name and code of one half of a month: 1-15, or 16 to the last day (28, 29, 30 or 31).
     *
     * @return array{year: int, month: int, half: int, period_start: string, period_end: string, name: string, code: string}
     */
    public static function forHalf(int $year, int $month, int $half): array
    {
        $first = Carbon::create($year, $month, 1);
        $start = $half === 1 ? $first->copy() : $first->copy()->day(16);
        $end = $half === 1 ? $first->copy()->day(15) : $first->copy()->endOfMonth();

        return [
            'year' => $year,
            'month' => $month,
            'half' => $half,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'name' => $first->format('F Y').' — '.($half === 1 ? '1st' : '2nd').' half ('.$start->format('j').'–'.$end->format('j').')',
            'code' => sprintf('%04d-%02d-%d', $year, $month, $half),
        ];
    }

    /**
     * Payslips can be added, changed or removed only while the period is a draft.
     */
    public function isDraft(): bool
    {
        return $this->status === null || $this->status === PayrollStatus::Draft;
    }

    public function isReleased(): bool
    {
        return $this->status === PayrollStatus::Released;
    }

    /**
     * A period goes only after its payslips do, and released payroll stays for the record.
     */
    public function deleteBlocker(): ?string
    {
        if ($this->isReleased()) {
            return 'Released payroll cannot be deleted.';
        }

        $payslips = $this->payslips()->count();

        return $payslips > 0 ? "This period still has {$payslips} payslip(s). Delete them first." : null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'period_id');
    }
}
