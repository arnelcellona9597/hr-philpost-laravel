<?php

use App\Support\Workdays;

it('counts Monday to Friday and skips holidays', function () {
    $count = Workdays::count('2026-03-02', '2026-03-08', ['2026-03-04']);

    expect($count['total'])->toBe(4)
        ->and($count['dates'])->toBe(['2026-03-02', '2026-03-03', '2026-03-05', '2026-03-06']);
});

it('charges a filing to each year it spans', function () {
    expect(Workdays::count('2025-12-30', '2026-01-02')['byYear'])->toBe([2025 => 2, 2026 => 2]);
});

it('returns zero for a weekend', function () {
    expect(Workdays::count('2026-03-07', '2026-03-08')['total'])->toBe(0);
});

it('refuses bad ranges', function (string $from, string $to, string $message) {
    expect(fn () => Workdays::count($from, $to))->toThrow(InvalidArgumentException::class, $message);
})->with([
    ['2026-02-30', '2026-03-01', 'Invalid date range'],
    ['2026-03-02', '2026-03-01', 'The end date is before the start date'],
    ['2024-01-01', '2025-06-01', 'Leave range is longer than a year'],
]);
