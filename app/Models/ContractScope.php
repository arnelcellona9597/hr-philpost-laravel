<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Database\Factories\ContractScopeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable Scope of Service: the duties for one kind of work, chosen when a contract is issued, with
 * the office and supervisor that usually go with it.
 */
#[Fillable(['name', 'position', 'office', 'place_of_service', 'items', 'active'])]
class ContractScope extends Model
{
    /** @use HasFactory<ContractScopeFactory> */
    use Audited, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['items' => 'array', 'active' => 'boolean'];
    }

    /**
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}
