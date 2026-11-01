<?php

namespace App\Models;

use App\Enums\PayChangeType;
use App\Enums\RateType;
use App\Models\Concerns\Audited;
use App\Support\Money;
use Database\Factories\PayChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in an employee's pay: the rate that applies from a date, and why it changed.
 */
#[Fillable(['employee_id', 'effective_on', 'change_type', 'rate_type', 'amount_centavos', 'wage_order_id', 'reference', 'remarks'])]
class PayChange extends Model
{
    /** @use HasFactory<PayChangeFactory> */
    use Audited, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_on' => 'date:Y-m-d',
            'change_type' => PayChangeType::class,
            'rate_type' => RateType::class,
            'amount_centavos' => 'integer',
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => $this->effective_on?->toDateString().' '.Money::format((int) $this->amount_centavos));
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<WageOrder, $this>
     */
    public function wageOrder(): BelongsTo
    {
        return $this->belongsTo(WageOrder::class);
    }
}
