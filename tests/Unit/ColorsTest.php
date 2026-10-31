<?php

use App\Support\Colors;

it('picks readable text for a fill', function () {
    expect(Colors::readableOn('#00539b'))->toBe('#ffffff')
        ->and(Colors::readableOn('#ffc72c'))->toBe('#000000');
});

it('computes contrast ratios', function () {
    expect(round(Colors::contrastRatio('#000000', '#ffffff'), 1))->toBe(21.0);
});

it('only lets real hex colors through', function () {
    $palette = Colors::resolve(['primary' => '#112233', 'danger' => 'red;background:url(x)', 'accent' => "#112233\n"]);

    expect($palette['primary'])->toBe('#112233')
        ->and($palette['danger'])->toBe('#c8102e')
        ->and($palette['accent'])->toBe('#ffc72c')
        ->and($palette['success'])->toBe('#15803d');
});
