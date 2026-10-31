<?php

use App\Support\GovId;

it('stores government IDs in canonical dashed form', function (string $kind, string $input, string $expected) {
    expect(GovId::format($kind, $input))->toBe($expected);
})->with([
    ['sss', '1234567890', '12-3456789-0'],
    ['pagibig', '1234 5678 9012', '1234-5678-9012'],
    ['philhealth', '123456789012', '12-345678901-2'],
    ['tin', '123456789', '123-456-789'],
    ['tin', '123-456-789-000', '123-456-789-000'],
    ['tin', '12345678900000', '123-456-789-00000'],
]);

it('keeps leading zeros', function () {
    expect(GovId::format('sss', '0034567890'))->toBe('00-3456789-0');
});

it('explains invalid IDs and leaves them unchanged', function () {
    expect(GovId::validate('sss', '123'))->toBe('SSS Number must have 10 digits (got 3)')
        ->and(GovId::validate('tin', '12345'))->toBe('TIN Number must have 9 or 12 or 14 digits (got 5)')
        ->and(GovId::validate('sss', 'abc'))->toBe('SSS Number may only contain digits and dashes')
        ->and(GovId::format('sss', ' 123 '))->toBe('123');
});

it('treats blank values as valid', function () {
    expect(GovId::validate('sss', ''))->toBeNull()
        ->and(GovId::validate('sss', null))->toBeNull()
        ->and(GovId::format('sss', ''))->toBeNull();
});

it('validates emails and phone numbers', function () {
    expect(GovId::validateEmail('juan@example.test'))->toBeNull()
        ->and(GovId::validateEmail('juan@example'))->not->toBeNull()
        ->and(GovId::validatePhone('+63 (917) 123-4567'))->toBeNull()
        ->and(GovId::validatePhone('call me'))->not->toBeNull();
});
