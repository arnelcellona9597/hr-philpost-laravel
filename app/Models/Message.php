<?php

namespace App\Models;

use App\Enums\MessageCategory;
use App\Enums\MessageStatus;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['subject', 'body', 'category', 'audience', 'automatic', 'related_collection', 'related_id', 'sent_by', 'sent_by_name'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
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
            'automatic' => 'boolean',
            'status' => MessageStatus::class,
            'total' => 'integer',
            'sent' => 'integer',
            'failed' => 'integer',
            'skipped' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * @return HasMany<MessageRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(MessageRecipient::class);
    }

    /**
     * @return BelongsToMany<Media, $this>
     */
    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'message_attachments');
    }
}
