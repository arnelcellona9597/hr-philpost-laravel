<?php

namespace App\Models;

use App\Enums\ImportJobStatus;
use Database\Factories\ImportJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['file_name', 'module', 'status', 'created', 'updated', 'unchanged', 'error', 'user_id', 'user_name', 'archive_file'])]
class ImportJob extends Model
{
    /** @use HasFactory<ImportJobFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportJobStatus::class,
            'created' => 'integer',
            'updated' => 'integer',
            'unchanged' => 'integer',
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
