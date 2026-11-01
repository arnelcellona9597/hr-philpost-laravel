<?php

namespace App\Models\Concerns;

use App\Support\AuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * Records who created, changed or deleted a record, field by field, in the same transaction.
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(fn (Model $model) => AuditTrail::created($model));
        static::updated(fn (Model $model) => AuditTrail::updated($model));
        static::deleted(fn (Model $model) => AuditTrail::deleted($model));
    }
}
