<?php

namespace App\Models;

use App\Enums\IpcrPeriod;
use App\Models\Concerns\Audited;
use App\Support\IpcrScale;
use Database\Factories\IpcrRatingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

#[Fillable(['employee_id', 'year', 'last_name', 'first_name', 'middle_name', 'date_submitted', 'date_received', 'remarks', 'station', 'job_title', 'rating_period', 'period_from', 'period_to', 'rating'])]
class IpcrRating extends ComplianceRecord
{
    /** @use HasFactory<IpcrRatingFactory> */
    use Audited, HasFactory;

    public const SLUG = 'ipcr-ratings';

    public const SINGULAR = 'IPCR Rating';

    public const SNAPSHOT = ['station', 'job_title'];

    /**
     * Round a new or changed rating the way IPCR forms report it and keep the adjectival rating in step.
     * A rating that isn't being changed keeps its stored value.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (IpcrRating $rating): void {
            if ($rating->exists && ! $rating->isDirty('rating')) {
                return;
            }

            $value = $rating->rating === null ? null : IpcrScale::round((float) $rating->rating);

            $rating->rating = $value;
            $rating->adjectival_rating = IpcrScale::adjectival($value) ?: null;
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
            ...parent::casts(),
            'rating_period' => IpcrPeriod::class,
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'rating' => 'decimal:2',
        ];
    }

    public function ratingPeriodValue(): ?string
    {
        return $this->rating_period?->value;
    }
}
