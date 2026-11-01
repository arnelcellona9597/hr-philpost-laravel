<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\IsSingleton;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['annual_allowance', 'year_overrides'])]
class LeaveSetting extends Model
{
    use Audited, IsSingleton;

    /**
     * Values for the row created on a fresh install.
     *
     * @return array<string, mixed>
     */
    protected static function defaultValues(): array
    {
        return [
            'annual_allowance' => 5,
            'year_overrides' => [],
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'annual_allowance' => 'integer',
            'year_overrides' => 'array',
        ];
    }

    /**
     * The allowance for a year: its override if one exists, else the annual default.
     */
    public function allowanceForYear(int $year): int
    {
        foreach ($this->year_overrides ?? [] as $override) {
            if ((int) $override['year'] === $year) {
                return (int) $override['allowance'];
            }
        }

        return $this->annual_allowance;
    }
}
