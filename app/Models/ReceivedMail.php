<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Database\Factories\ReceivedMailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of mail logged when it arrives at the office.
 */
#[Fillable(['received_on', 'tracking_number', 'post_office', 'description', 'remarks', 'received_by'])]
class ReceivedMail extends Model
{
    /** @use HasFactory<ReceivedMailFactory> */
    use Audited, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['received_on' => 'date:Y-m-d'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
