<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['template_id', 'employee_id', 'purpose', 'issued_date', 'void_reason'])]
class Certificate extends Model
{
    /** @use HasFactory<CertificateFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_date' => 'date:Y-m-d',
            'content' => 'array',
            'status' => CertificateStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    public function isVoid(): bool
    {
        return $this->status === CertificateStatus::Void;
    }

    /**
     * @return BelongsTo<CertificateTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CertificateTemplate::class, 'template_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
