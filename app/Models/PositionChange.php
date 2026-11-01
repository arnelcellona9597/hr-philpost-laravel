<?php

namespace App\Models;

use App\Enums\PositionChangeType;
use App\Models\Concerns\Audited;
use Database\Factories\PositionChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A promotion or demotion on an employee's record.
 */
#[Fillable(['employee_id', 'effective_on', 'change_type', 'from_position', 'to_position', 'remarks'])]
class PositionChange extends Model
{
    /** @use HasFactory<PositionChangeFactory> */
    use Audited, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_on' => 'date:Y-m-d',
            'change_type' => PositionChangeType::class,
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->change_type?->value} to {$this->to_position}");
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
