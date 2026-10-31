<?php

namespace App\Enums;

enum HolidayType: string
{
    case RegularHoliday = 'Regular Holiday';
    case SpecialNonWorkingDay = 'Special Non-Working Day';
    case LocalHoliday = 'Local Holiday';
    case OfficeClosure = 'Office Closure';
}
