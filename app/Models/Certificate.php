<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use App\Exceptions\UserFacingException;
use App\Models\Concerns\Audited;
use App\Support\Names;
use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['template_id', 'employee_id', 'purpose', 'issued_date', 'void_reason'])]
class Certificate extends Model
{
    /** @use HasFactory<CertificateFactory> */
    use Audited, HasFactory;

    private const VOID_COLUMNS = ['status', 'void_reason', 'voided_at', 'updated_at'];

    /**
     * Issued certificates are records: they can be voided, and nothing else about them changes.
     */
    protected static function booted(): void
    {
        static::updating(function (Certificate $certificate): void {
            if (array_diff(array_keys($certificate->getDirty()), self::VOID_COLUMNS) !== []) {
                throw new UserFacingException('An issued certificate cannot be changed. Void it and issue a new one instead.');
            }

            if ($certificate->getOriginal('status') === CertificateStatus::Void && $certificate->isDirty(self::VOID_COLUMNS)) {
                throw new UserFacingException('A voided certificate cannot be made valid again. Issue a new one instead.');
            }
        });
    }

    /**
     * The employee's name in title case for lists and headings; the issued text is unchanged.
     *
     * @return Attribute<string, never>
     */
    protected function employeeName(): Attribute
    {
        return Attribute::get(fn (?string $value): string => Names::title($value));
    }

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
