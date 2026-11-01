<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\IsSingleton;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['itr', 'sworn', 'pds', 'ipcr_jan_jun', 'ipcr_jul_dec', 'overrides'])]
class RequirementDeadlineSetting extends Model
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
            'itr' => ['month' => 4, 'day' => 30, 'next_year' => false],
            'sworn' => ['month' => 4, 'day' => 30, 'next_year' => true],
            'pds' => ['month' => 6, 'day' => 30, 'next_year' => false],
            'ipcr_jan_jun' => ['month' => 7, 'day' => 15, 'next_year' => false],
            'ipcr_jul_dec' => ['month' => 1, 'day' => 15, 'next_year' => true],
            'overrides' => [],
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
            'itr' => 'array',
            'sworn' => 'array',
            'pds' => 'array',
            'ipcr_jan_jun' => 'array',
            'ipcr_jul_dec' => 'array',
            'overrides' => 'array',
        ];
    }
}
