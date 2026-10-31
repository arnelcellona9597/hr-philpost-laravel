<?php

namespace App\Enums;

enum ImportJobStatus: string
{
    case Committed = 'committed';
    case Failed = 'failed';
}
