<?php

namespace App\Enums;

enum PayChangeType: string
{
    case Starting = 'Starting rate';
    case WageOrder = 'Wage order';
    case Increase = 'Salary increase';
    case Promotion = 'Promotion';
    case Demotion = 'Demotion';
    case Correction = 'Correction';
}
