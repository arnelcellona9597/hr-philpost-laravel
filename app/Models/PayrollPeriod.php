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

#[Fillable(['name', 'code', 'period_start', 'period_end', 'pay_date', 'notes'])]
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
        ];
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
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'period_id');
    }
}
