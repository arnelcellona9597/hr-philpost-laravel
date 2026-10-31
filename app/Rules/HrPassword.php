<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class HrPassword implements ValidationRule
{
    public const MIN_LENGTH = 10;

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = self::problem($value);

        if ($problem !== null) {
            $fail($problem);
        }
    }

    /**
     * What is wrong with a password, or null when it is acceptable.
     */
    public static function problem(mixed $password): ?string
    {
        if (! is_string($password) || mb_strlen($password) < self::MIN_LENGTH) {
            return 'Password must be at least '.self::MIN_LENGTH.' characters.';
        }

        if (! preg_match('/[A-Za-z]/', $password) || ! preg_match('/\d/', $password)) {
            return 'Password must contain both letters and numbers.';
        }

        return null;
    }
}
