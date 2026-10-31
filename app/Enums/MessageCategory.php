<?php

namespace App\Enums;

enum MessageCategory: string
{
    case Leave = 'Leave';
    case Payroll = 'Payroll';
    case Onboarding = 'Onboarding';
    case Announcement = 'Announcement';
    case Reminder = 'Reminder';
    case General = 'General';
    case System = 'System';
}
