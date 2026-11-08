<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Database\Factories\BranchUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A station, division, section or unit under a branch. It may have no branch until HR places it.
 */
#[Fillable(['branch_id', 'name'])]
class BranchUnit extends Model
{
    /** @use HasFactory<BranchUnitFactory> */
    use Audited, HasFactory;

    protected static function booted(): void
    {
        // Placing a unit under a branch moves the people in it to that branch.
        static::saved(function (BranchUnit $unit): void {
            if ($unit->branch_id !== null && $unit->wasChanged('branch_id')) {
                $unit->employees()->where(fn ($query) => $query->whereNull('station_id')->orWhere('station_id', '<>', $unit->branch_id))->get()->each->update(['station_id' => $unit->branch_id]);
            }
        });
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'unit_id');
    }

    /**
     * "Mintal Post Office › Accounting Section", or the unit alone while it has no branch.
     */
    public function label(): string
    {
        return $this->branch ? "{$this->branch->name} › {$this->name}" : "{$this->name} (no branch yet)";
    }

    /**
     * Why this unit can't be deleted yet, if anyone is still in it.
     */
    public function deleteBlocker(): ?string
    {
        $employees = $this->employees()->count();

        return $employees > 0 ? "{$employees} employee(s) are still in this unit. Move them to another unit first." : null;
    }
}
