<?php

namespace App\Enums;

enum LoginChallengePurpose: string
{
    case Login = 'login';
    case Invite = 'invite';
    case Reset = 'reset';
}
