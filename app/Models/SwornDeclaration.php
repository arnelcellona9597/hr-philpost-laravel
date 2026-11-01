<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Database\Factories\SwornDeclarationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

#[Fillable(['employee_id', 'year', 'last_name', 'first_name', 'middle_name', 'date_submitted', 'date_received', 'remarks', 'station', 'job_title', 'tin'])]
class SwornDeclaration extends ComplianceRecord
{
    /** @use HasFactory<SwornDeclarationFactory> */
    use Audited, HasFactory;

    public const SLUG = 'sworn-declarations';

    public const SINGULAR = 'Sworn Declaration';

    public const SNAPSHOT = ['station', 'job_title', 'tin'];
}
