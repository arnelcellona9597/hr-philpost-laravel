<?php

namespace App\Models;

use App\Enums\ThemeMode;
use App\Models\Concerns\Audited;
use App\Models\Concerns\IsSingleton;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_name', 'tagline', 'logo_id', 'palette', 'default_theme', 'letterhead_top', 'letterhead_name', 'letterhead_details', 'letterhead_section', 'letterhead_logo_id'])]
class SiteSetting extends Model
{
    use Audited, IsSingleton;

    public const DEFAULT_PALETTE = [
        'primary' => '#00539b',
        'secondary' => '#777777',
        'accent' => '#ffc72c',
        'success' => '#15803d',
        'warning' => '#ffc72c',
        'danger' => '#c8102e',
    ];

    /**
     * Values for the row created on a fresh install.
     *
     * @return array<string, mixed>
     */
    protected static function defaultValues(): array
    {
        return [
            'company_name' => 'HR Management System',
            'palette' => self::DEFAULT_PALETTE,
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
            'palette' => 'array',
            'default_theme' => ThemeMode::class,
        ];
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'logo_id');
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function letterheadLogo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'letterhead_logo_id');
    }
}
