<?php

namespace App\Models;

use App\Enums\HolidayType;
use App\Models\Concerns\Audited;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['date', 'name', 'type'])]
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use Audited, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'type' => HolidayType::class,
        ];
    }
}
