<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Database\Factories\ItrSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

#[Fillable(['employee_id', 'year', 'last_name', 'first_name', 'middle_name', 'date_submitted', 'date_received', 'remarks', 'extension'])]
class ItrSubmission extends ComplianceRecord
{
    /** @use HasFactory<ItrSubmissionFactory> */
    use Audited, HasFactory;

    public const SLUG = 'itr-submissions';

    public const SINGULAR = 'Annual Income Tax Return';

    public const SNAPSHOT = ['extension'];
}
