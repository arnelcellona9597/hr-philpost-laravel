<?php

namespace App\Enums;

enum CertificateStatus: string
{
    case Valid = 'Valid';
    case Void = 'Void';
}
