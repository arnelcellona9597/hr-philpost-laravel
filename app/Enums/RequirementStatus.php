<?php

namespace App\Enums;

enum RequirementStatus: string
{
    case Pending = 'Pending';
    case Submitted = 'Submitted';
    case Verified = 'Verified';
    case NotApplicable = 'N/A';
}
