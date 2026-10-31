<?php

namespace App\Models;

use Database\Factories\PdsSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

#[Fillable(['employee_id', 'year', 'last_name', 'first_name', 'middle_name', 'date_submitted', 'date_received', 'remarks', 'station', 'job_title'])]
class PdsSubmission extends ComplianceRecord
{
    /** @use HasFactory<PdsSubmissionFactory> */
    use HasFactory;

    public const SLUG = 'pds-submissions';
}
