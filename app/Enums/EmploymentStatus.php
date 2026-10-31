<?php

namespace App\Enums;

enum EmploymentStatus: string
{
    case Active = 'Active';
    case Resigned = 'Resigned';
    case Retired = 'Retired';
    case Terminated = 'Terminated';
}
