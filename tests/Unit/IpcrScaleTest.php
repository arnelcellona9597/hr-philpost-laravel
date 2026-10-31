<?php

use App\Support\IpcrScale;

it('maps ratings to the adjectival scale', function (?float $rating, string $label) {
    expect(IpcrScale::adjectival($rating))->toBe($label);
})->with([
    [5.0, 'Outstanding'],
    [4.5, 'Outstanding'],
    [4.499, 'Very Satisfactory'],
    [3.5, 'Very Satisfactory'],
    [2.5, 'Satisfactory'],
    [1.5, 'Unsatisfactory'],
    [1.0, 'Poor'],
    [0.9, ''],
    [5.1, ''],
    [null, ''],
]);

it('rounds to three decimals before applying the scale', function () {
    expect(IpcrScale::round(4.4996))->toBe(4.5)
        ->and(IpcrScale::adjectival(4.4996))->toBe('Outstanding');
});
