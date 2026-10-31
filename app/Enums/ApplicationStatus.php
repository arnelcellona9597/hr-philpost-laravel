<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case Pending = 'Pending';
    case ForInterview = 'For Interview';
    case ForOjt = 'For OJT';
    case Hired = 'Hired';
    case NotHired = 'Not Hired';
    case Withdrawn = 'Withdrawn';
}
