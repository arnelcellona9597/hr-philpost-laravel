<?php

use App\Support\Money;

it('parses amounts into whole centavos', function (mixed $input, int $expected) {
    expect(Money::toCentavos($input))->toBe($expected);
})->with([
    [1234.5, 123450],
    ['1,234.50', 123450],
    ['₱ 1,234.50', 123450],
    ['PHP 1234', 123400],
    ['p5', 500],
    [0.07, 7],
    [19.99, 1999],
    ['', 0],
    [null, 0],
]);

it('rejects bad amounts', function (mixed $input, string $message) {
    expect(fn () => Money::toCentavos($input))->toThrow(InvalidArgumentException::class, $message);
})->with([
    ['-5', 'cannot be negative'],
    ['1.234', 'can have at most 2 decimal places'],
    ['1000000000.01', 'is too large'],
    ['abc', '"abc" is not an amount'],
    [INF, 'is not a number'],
]);

it('allows exactly one billion pesos', function () {
    expect(Money::toCentavos('1,000,000,000'))->toBe(Money::MAX_CENTAVOS);
});

it('formats centavos', function () {
    expect(Money::format(123456))->toBe('₱1,234.56')
        ->and(Money::format(-5))->toBe('−₱0.05')
        ->and(Money::format(100, 'USD '))->toBe('USD 1.00');
});
