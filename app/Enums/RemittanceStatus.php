<?php

namespace App\Enums;

enum RemittanceStatus: string
{
    case Draft = 'Draft';
    case Finalized = 'Finalized';
}
