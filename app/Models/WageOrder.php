<?php

namespace App\Models;

use App\Enums\Region;
use App\Models\Concerns\Audited;
use App\Support\HrDate;
use Database\Factories\WageOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A minimum wage set for a region and sector from a given date. A blank area covers the whole region;
 * a named area (e.g. Cotabato City) covers only the branches and units placed in that area.
 */
#[Fillable(['region', 'area', 'order_number', 'sector', 'daily_rate_centavos', 'effective_on', 'remarks'])]
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
     * Blank means the whole region, stored as ''.
     *
     * @return Attribute<string, string|null>
     */
    protected function area(): Attribute
    {
        return Attribute::make(get: fn (?string $value): string => (string) $value, set: fn (?string $value): string => trim((string) $value));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->display_name} ({$this->coverage})");
    }

    /**
     * "BARMM · Cotabato City", or the region alone for a region-wide order.
     *
     * @return Attribute<string, never>
     */
    protected function coverage(): Attribute
    {
        return Attribute::get(fn (): string => $this->region?->value.($this->area !== '' && $this->area !== null ? ' · '.$this->area : ''));
    }

    /**
     * The order number, or the date when the number isn't known yet.
     *
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => $this->order_number ?: 'Rate from '.HrDate::format($this->effective_on));
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
