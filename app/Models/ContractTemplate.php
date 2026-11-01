<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Database\Factories\ContractTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'prefix', 'paper_size', 'font', 'margin', 'blocks', 'active', 'sort_order'])]
class ContractTemplate extends Model
{
    /** @use HasFactory<ContractTemplateFactory> */
    use Audited, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Issued contracts point at their template, so it stays for the record.
     */
    public function deleteBlocker(): ?string
    {
        return $this->contracts()->exists()
            ? 'Contracts were issued from this template, so it is kept for the record. Make it inactive instead.'
            : null;
    }

    /**
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'template_id');
    }
}
