<?php

namespace App\Models;

use App\Models\Concerns\IsSingleton;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['host', 'port', 'security', 'username', 'password', 'from_address', 'from_name'])]
#[Hidden(['password'])]
class SmtpSetting extends Model
{
    use IsSingleton;

    /**
     * Values for the row created on a fresh install.
     *
     * @return array<string, mixed>
     */
    protected static function defaultValues(): array
    {
        return [
            'port' => 465,
            'security' => 'ssl',
            'from_name' => 'HR System',
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
            'port' => 'integer',
            'password' => 'encrypted',
        ];
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function passwordSet(): Attribute
    {
        return Attribute::get(fn (): bool => $this->getRawOriginal('password') !== null);
    }
}
