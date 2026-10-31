<?php

namespace App\Enums;

enum PayrollStatus: string
{
    case Draft = 'Draft';
    case Released = 'Released';
}
