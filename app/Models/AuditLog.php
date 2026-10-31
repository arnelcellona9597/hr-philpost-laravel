<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'user_name', 'action', 'collection_slug', 'doc_id', 'doc_label', 'changes'])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /**
     * Collections whose history only System Admins may see.
     */
    public const ADMIN_ONLY_COLLECTIONS = ['users', 'smtp-settings'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    /**
     * Limit history to what the given user may read.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSystemAdmin()) {
            return $query;
        }

        return $user->isApproved() ? $query->whereNotIn('collection_slug', self::ADMIN_ONLY_COLLECTIONS) : $query->whereRaw('1 = 0');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
