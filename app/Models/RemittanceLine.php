<?php

namespace App\Models;

use App\Support\Names;
use Database\Factories\RemittanceLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person on a remittance. The name and numbers are copied in, so an old month never changes.
 */
#[Fillable(['employee_id', 'position', 'last_name', 'first_name', 'middle_name', 'extension', 'birth_date', 'station', 'member_number', 'amount_centavos', 'employer_share_centavos', 'compensation_centavos', 'remarks'])]
class RemittanceLine extends Model
{
    /** @use HasFactory<RemittanceLineFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'amount_centavos' => 'integer',
            'employer_share_centavos' => 'integer',
            'compensation_centavos' => 'integer',
        ];
    }

    /**
     * "LAST, First M. Ext." as the office lists it.
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => Names::lastFirst($this->last_name, $this->first_name, $this->middle_name, $this->extension));
    }

    /**
     * @return BelongsTo<Remittance, $this>
     */
    public function remittance(): BelongsTo
    {
        return $this->belongsTo(Remittance::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
