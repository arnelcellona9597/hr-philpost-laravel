<?php

namespace App\Support;

/**
 * CSC Strategic Performance Management System adjectival rating scale.
 */
class IpcrScale
{
    public const MIN = 1;

    public const MAX = 5;

    private const SCALE = [
        ['min' => 4.5, 'label' => 'Outstanding'],
        ['min' => 3.5, 'label' => 'Very Satisfactory'],
        ['min' => 2.5, 'label' => 'Satisfactory'],
        ['min' => 1.5, 'label' => 'Unsatisfactory'],
        ['min' => 1.0, 'label' => 'Poor'],
    ];

    public static function round(float $rating): float
    {
        return round($rating, 3);
    }

    public static function adjectival(?float $rating): string
    {
        if ($rating === null) {
            return '';
        }

        $rounded = self::round($rating);

        if ($rounded < self::MIN || $rounded > self::MAX) {
            return '';
        }

        foreach (self::SCALE as $step) {
            if ($rounded >= $step['min']) {
                return $step['label'];
            }
        }

        return '';
    }
}
