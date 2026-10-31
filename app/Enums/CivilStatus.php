<?php

namespace App\Enums;

enum CivilStatus: string
{
    case Single = 'Single';
    case Married = 'Married';
    case Widowed = 'Widowed';
    case Separated = 'Separated';
    case Annulled = 'Annulled';
    case SoloParent = 'Solo Parent';
}
