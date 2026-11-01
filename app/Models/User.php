<?php

namespace App\Models;

use App\Enums\Role;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserStatus;
use App\Exceptions\UserFacingException;
use App\Models\Concerns\Audited;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'login_attempts', 'locked_until'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Audited, HasFactory, Notifiable;

    /**
     * Keep approval and verification in step, and never leave the system without a System Admin.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if ($user->status === UserStatus::Approved) {
                $user->approved_at ??= now();
            }
        });

        static::updating(function (User $user): void {
            if ($user->isDirty('email') && strcasecmp($user->getOriginal('email'), $user->email) !== 0) {
                $user->email_verified_at = null;
            }

            if ($user->isDirty('status') && $user->status === UserStatus::Approved) {
                $user->approved_at = now();
            }

            if ($user->wasActiveSystemAdmin() && ! $user->isSystemAdmin()) {
                $user->ensureAnotherSystemAdminRemains();
            }
        });

        static::deleting(function (User $user): void {
            if ($user->wasActiveSystemAdmin()) {
                $user->ensureAnotherSystemAdminRemains();
            }
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
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'locked_until' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'status' => UserStatus::class,
        ];
    }

    /**
     * Only approved accounts count as signed in.
     */
    public function isApproved(): bool
    {
        return $this->status === UserStatus::Approved;
    }

    public function isSystemAdmin(): bool
    {
        return $this->isApproved() && $this->role === Role::SystemAdmin;
    }

    public function isLocked(): bool
    {
        return $this->locked_until?->isFuture() ?? false;
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_id');
    }

    /**
     * In-app bell notifications, which are separate from Laravel's database notification channel.
     *
     * @return HasMany<Notification, $this>
     */
    public function hrNotifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * @return HasMany<LoginChallenge, $this>
     */
    public function challenges(): HasMany
    {
        return $this->hasMany(LoginChallenge::class);
    }

    private function wasActiveSystemAdmin(): bool
    {
        return $this->getOriginal('role') === Role::SystemAdmin && $this->getOriginal('status') === UserStatus::Approved;
    }

    /**
     * @throws UserFacingException
     */
    private function ensureAnotherSystemAdminRemains(): void
    {
        $others = self::query()
            ->whereKeyNot($this->id)
            ->where('role', Role::SystemAdmin)
            ->where('status', UserStatus::Approved)
            ->lockForUpdate()
            ->count();

        if ($others === 0) {
            throw new UserFacingException('At least one approved System Admin must remain.');
        }
    }
}
