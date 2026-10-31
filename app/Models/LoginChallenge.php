<?php

namespace App\Models;

use App\Enums\LoginChallengePurpose;
use Database\Factories\LoginChallengeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['key', 'purpose', 'user_id', 'code_hash', 'expires_at', 'last_sent_at', 'ip'])]
#[Hidden(['key', 'code_hash'])]
class LoginChallenge extends Model
{
    /** @use HasFactory<LoginChallengeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => LoginChallengePurpose::class,
            'expires_at' => 'datetime',
            'attempts' => 'integer',
            'send_count' => 'integer',
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
