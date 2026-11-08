<?php

namespace App\Models;

use App\Enums\Region;
use App\Models\Concerns\Audited;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'address', 'region', 'contact_number', 'notes'])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use Audited, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'region' => Region::class,
        ];
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'station_id');
    }

    /**
     * @return HasMany<BranchUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(BranchUnit::class);
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'station_id');
    }

    /**
     * Why this branch can't be deleted yet, if anything still uses it.
     */
    public function deleteBlocker(): ?string
    {
        $employees = $this->employees()->count();
        $applications = $this->applications()->count();
        $units = $this->units()->count();

        if ($employees + $applications + $units === 0) {
            return null;
        }

        return $units > 0
            ? "This branch still has {$employees} employee(s), {$applications} application(s) and {$units} unit(s). Reassign or remove them first."
            : "This branch still has {$employees} employee(s) and {$applications} application(s). Reassign them first.";
    }
}
