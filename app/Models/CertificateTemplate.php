<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Database\Factories\CertificateTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'prefix', 'paper_size', 'font', 'margin', 'blocks', 'active', 'sort_order'])]
class CertificateTemplate extends Model
{
    /** @use HasFactory<CertificateTemplateFactory> */
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
     * Issued certificates point at their template, so it stays for the record.
     */
    public function deleteBlocker(): ?string
    {
        return $this->certificates()->exists()
            ? 'Certificates were issued from this template, so it is kept for the record. Make it inactive instead.'
            : null;
    }

    /**
     * @return HasMany<Certificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'template_id');
    }
}
