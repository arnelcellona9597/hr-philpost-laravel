<?php

namespace App\Models;

use App\Enums\ThemeMode;
use App\Models\Concerns\Audited;
use App\Models\Concerns\IsSingleton;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_name', 'tagline', 'logo_id', 'favicon_id', 'palette', 'default_theme', 'letterhead_top', 'letterhead_name', 'letterhead_details', 'letterhead_section', 'letterhead_logo_id'])]
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
     * The printed letterhead with its fallbacks: the company name and the main logo.
     *
     * @return array{top: string, name: list<string>, details: list<string>, section: string, logoId: int|null}
     */
    public function letterhead(): array
    {
        $lines = fn (string $text): array => array_values(array_filter(array_map(trim(...), explode("\n", $text))));

        return [
            'top' => $this->letterhead_top ?: 'Republic of the Philippines',
            'name' => $lines((string) ($this->letterhead_name ?: mb_strtoupper($this->company_name))),
            'details' => $lines((string) $this->letterhead_details),
            'section' => (string) $this->letterhead_section,
            'logoId' => $this->letterhead_logo_id ?: $this->logo_id,
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
    public function favicon(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'favicon_id');
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function letterheadLogo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'letterhead_logo_id');
    }
}
