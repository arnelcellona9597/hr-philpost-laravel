<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\IsSingleton;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The officials who sign every contract, and the wage wording shared by all of them.
 */
#[Fillable(['area_director_name', 'area_director_title', 'adfin_chief_name', 'adfin_chief_title', 'hrm_chief_name', 'hrm_chief_title', 'budget_officer_name', 'budget_officer_title', 'default_place_of_service', 'wage_order', 'premium_percent'])]
class ContractSetting extends Model
{
    use Audited, IsSingleton;

    /**
     * The four officials, in the order they sign.
     */
    public const OFFICIALS = [
        'area_director' => 'Area Director',
        'adfin_chief' => 'Chief, Administrative and Finance Division',
        'hrm_chief' => 'Chief, Human Resource Management Section',
        'budget_officer' => 'Budget Officer',
    ];

    /**
     * Values for the row created on a fresh install. The officials' names are left blank for the office to fill in.
     *
     * @return array<string, mixed>
     */
    protected static function defaultValues(): array
    {
        return [
            'area_director_title' => self::OFFICIALS['area_director'],
            'adfin_chief_title' => self::OFFICIALS['adfin_chief'],
            'hrm_chief_title' => self::OFFICIALS['hrm_chief'],
            'budget_officer_title' => self::OFFICIALS['budget_officer'],
            'premium_percent' => 15,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['premium_percent' => 'integer'];
    }
}
