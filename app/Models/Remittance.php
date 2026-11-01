<?php

namespace App\Models;

use App\Enums\RemittanceKind;
use App\Enums\RemittanceStatus;
use App\Models\Concerns\Audited;
use Database\Factories\RemittanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One month of contributions for one fund, listing the people it was paid for.
 */
#[Fillable(['kind', 'year', 'month', 'status', 'voucher_number', 'voucher_date', 'mode_of_payment', 'notes', 'finalized_at'])]
class Remittance extends Model
{
    /** @use HasFactory<RemittanceFactory> */
    use Audited, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RemittanceKind::class,
            'status' => RemittanceStatus::class,
            'voucher_date' => 'date:Y-m-d',
            'finalized_at' => 'datetime',
        ];
    }

    /**
     * "SSS — October 2026", used for the audit log and page headings.
     *
     * @return Attribute<string, never>
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => $this->kind->label().' — '.$this->periodLabel());
    }

    public function periodLabel(): string
    {
        return Carbon::create($this->year, $this->month, 1)->format('F Y');
    }

    public function isFinalized(): bool
    {
        return $this->status === RemittanceStatus::Finalized;
    }

    /**
     * @return HasMany<RemittanceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RemittanceLine::class)->orderBy('position')->orderBy('id');
    }
}
