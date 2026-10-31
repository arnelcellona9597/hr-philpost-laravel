<?php

namespace App\Models;

use App\Enums\MessageCategory;
use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'category', 'subject', 'body'])]
class EmailTemplate extends Model
{
    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => MessageCategory::class,
        ];
    }

    /**
     * Keyed templates drive automatic emails, so they can be edited but not deleted.
     */
    public function isSystemTemplate(): bool
    {
        return $this->key !== null;
    }
}
