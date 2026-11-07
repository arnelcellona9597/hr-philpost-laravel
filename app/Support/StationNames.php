<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Turns the many ways a station is written ("DCCPO", "Mintal P.O", "Tagum City Post Office, Davao del Norte")
 * into one name per office, so the same office is not created several times.
 */
class StationNames
{
    /**
     * Short forms used in the office's records.
     *
     * @var array<string, string>
     */
    private const ABBREVIATIONS = [
        'dccpo' => 'Davao City Central Post Office',
        'mdc' => 'Mail Distribution Center',
        'gsc' => 'General Santos City Post Office',
        'gsc post office' => 'General Santos City Post Office',
        'adfin' => 'Administrative and Finance Division',
    ];

    public static function canonical(?string $text): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $text));

        if ($name === '') {
            return '';
        }

        // Sections and divisions keep their full wording, such as "Accounting Section, AdFin Division".
        if (! preg_match('/\b(section|division|unit)\b/i', $name)) {
            $name = trim(Str::before($name, ','));
        }

        $name = preg_replace('/\bP\.?\s?O\.?(?=$|\s|\()/iu', 'Post Office', $name);
        $name = preg_replace('/\bpost office\b/iu', 'Post Office', $name);
        $name = preg_replace('/\bGen\.\s*/iu', 'General ', $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name), ' ,.');

        $name = self::ABBREVIATIONS[mb_strtolower($name)] ?? $name;

        // A bare place name ("Bunawan", "Cotabato City") is that place's post office.
        if (! preg_match('/\b(post office|section|division|unit|center|department|office|area|coa|extn)\b/i', $name)) {
            $name .= ' Post Office';
        }

        return $name;
    }

    /**
     * What two spellings of one office share: "Tandag Post Office" and "Tandag City Post Office".
     */
    public static function looseKey(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', str_replace(' city ', ' ', ' '.mb_strtolower($name).' '));
    }
}
