<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Partial = 'partial';
    case Failed = 'failed';
}
