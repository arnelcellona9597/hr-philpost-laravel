<?php

namespace App\Models;

use App\Enums\Region;
use App\Models\Concerns\Audited;
use Database\Factories\WageOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A minimum wage set for a region and sector from a given date.
 */
#[Fillable(['region', 'order_number', 'sector', 'daily_rate_centavos', 'effective_on', 'remarks'])]
class WageOrder extends Model
{
    /** @use HasFactory<WageOrderFactory> */
    use Audited, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'region' => Region::class,
            'effective_on' => 'date:Y-m-d',
            'daily_rate_centavos' => 'integer',
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->order_number} ({$this->region?->value})");
    }

    /**
     * Employees' pay records point at the order, so it stays for the record.
     */
    public function deleteBlocker(): ?string
    {
        $applied = $this->payChanges()->count();

        return $applied > 0 ? "This wage order was applied to {$applied} employee(s), so it is kept for the record." : null;
    }

    /**
     * @return HasMany<PayChange, $this>
     */
    public function payChanges(): HasMany
    {
        return $this->hasMany(PayChange::class);
    }
}
