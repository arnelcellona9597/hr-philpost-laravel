<?php

namespace App\Models;

use App\Enums\RemittanceKind;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The editable template for one fund: titles, employer details, signatories and voucher wording.
 */
#[Fillable(['title', 'letterhead_lines', 'employer_id', 'employer_name', 'employer_address', 'employer_contact', 'form_code', 'membership_program', 'default_amount_centavos', 'default_employer_share_centavos', 'default_compensation_centavos', 'service_charge_basis_points', 'prepared_by_name', 'prepared_by_title', 'noted_by_name', 'noted_by_title', 'certification_text', 'voucher_payee', 'voucher_address', 'voucher_particulars'])]
class RemittanceSetting extends Model
{
    use Audited;

    /**
     * The settings for a fund, created with the wording from the office's own forms on first use.
     */
    public static function forKind(RemittanceKind $kind): static
    {
        return static::query()->where('kind', $kind->value)->first()
            ?? static::unguarded(fn (): static => static::query()->firstOrCreate(['kind' => $kind->value], $kind->defaults())->refresh());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => RemittanceKind::class];
    }
}
