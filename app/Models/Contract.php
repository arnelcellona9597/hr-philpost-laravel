<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Exceptions\UserFacingException;
use App\Models\Concerns\Audited;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An issued Contract of Service. The merged text is frozen in `content`, so it never changes after issue.
 */
#[Fillable(['template_id', 'employee_id', 'void_reason'])]
class Contract extends Model
{
    /** @use HasFactory<ContractFactory> */
    use Audited, HasFactory;

    private const VOID_COLUMNS = ['status', 'void_reason', 'voided_at', 'updated_at'];

    /**
     * Issued contracts are records: they can be voided, and nothing else about them changes.
     */
    protected static function booted(): void
    {
        static::updating(function (Contract $contract): void {
            if (array_diff(array_keys($contract->getDirty()), self::VOID_COLUMNS) !== []) {
                throw new UserFacingException('An issued contract cannot be changed. Void it and issue a new one instead.');
            }

            if ($contract->getOriginal('status') === ContractStatus::Void && $contract->isDirty(self::VOID_COLUMNS)) {
                throw new UserFacingException('A voided contract cannot be made valid again. Issue a new one instead.');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'term_start' => 'date:Y-m-d',
            'term_end' => 'date:Y-m-d',
            'second_wage_from' => 'date:Y-m-d',
            'content' => 'array',
            'status' => ContractStatus::class,
            'voided_at' => 'datetime',
            'daily_wage_centavos' => 'integer',
            'second_wage_centavos' => 'integer',
        ];
    }

    public function isVoid(): bool
    {
        return $this->status === ContractStatus::Void;
    }

    /**
     * @return BelongsTo<ContractTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'template_id');
    }

    /**
     * @return BelongsTo<ContractScope, $this>
     */
    public function scope(): BelongsTo
    {
        return $this->belongsTo(ContractScope::class, 'contract_scope_id');
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
