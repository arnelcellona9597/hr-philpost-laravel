<?php

namespace App\Enums;

enum Classification: string
{
    case Cos = 'COS';
    case Contractual = 'Contractual';
    case Regular = 'Regular';

    /**
     * The wording used on history tables and printed profiles.
     */
    public function historyLabel(): string
    {
        return match ($this) {
            self::Cos => 'Contract of Service (COS)',
            self::Contractual => 'Contractual',
            self::Regular => 'Regular Employee',
        };
    }
}
