<?php

use App\Support\HrDate;
use Illuminate\Support\Carbon;

it('rejects dates that are not on the calendar', function (string $date, bool $valid) {
    expect(HrDate::isValid($date))->toBe($valid);
})->with([
    ['2024-02-29', true],
    ['2023-02-29', false],
    ['2024-13-01', false],
    ['1899-12-31', false],
    ['03/05/2024', false],
]);

it('reads today in Manila rather than UTC', function () {
    expect(HrDate::today(Carbon::parse('2026-03-01T16:30:00Z')))->toBe('2026-03-02')
        ->and(HrDate::today(Carbon::parse('2026-03-01T15:30:00Z')))->toBe('2026-03-01');
});

it('converts date-like values to a date string', function (mixed $value, ?string $expected) {
    expect(HrDate::toYmd($value))->toBe($expected);
})->with([
    ['2024-03-05', '2024-03-05'],
    ['  2024-03-05 ', '2024-03-05'],
    ['2024-03-04T16:00:00.000Z', '2024-03-05'],
    ['2023-02-29', null],
    ['not a date', null],
    ['', null],
    [null, null],
    [20240305, null],
]);

it('ages Feb-29 birthdays on Mar-1 in common years', function () {
    expect(HrDate::ageOn('2000-02-29', '2023-02-28'))->toBe(22)
        ->and(HrDate::ageOn('2000-02-29', '2023-03-01'))->toBe(23)
        ->and(HrDate::ageOn('2030-01-01', '2026-01-01'))->toBeNull();
});

it('computes calendar differences', function () {
    expect(HrDate::diff('2024-01-31', '2024-02-29'))->toBe(['years' => 0, 'months' => 0, 'days' => 29])
        ->and(HrDate::diff('2024-01-31', '2024-03-31'))->toBe(['years' => 0, 'months' => 2, 'days' => 0])
        ->and(HrDate::diff('2020-03-15', '2024-05-20'))->toBe(['years' => 4, 'months' => 2, 'days' => 5])
        ->and(HrDate::diff('2024-05-20', '2024-05-19'))->toBeNull();
});

it('ends length of service at the last day only once that day has passed', function () {
    $today = '2026-06-01';

    expect(HrDate::lengthOfService('2025-06-01', '2026-01-01', $today))->toBe(['years' => 0, 'months' => 7, 'days' => 0])
        ->and(HrDate::lengthOfService('2025-06-01', '2026-12-31', $today))->toBe(['years' => 1, 'months' => 0, 'days' => 0])
        ->and(HrDate::lengthOfService(null, null, $today))->toBeNull();
});

it('formats spans', function () {
    expect(HrDate::formatSpan(['years' => 1, 'months' => 2, 'days' => 9]))->toBe('1 yr 2 mos')
        ->and(HrDate::formatSpan(['years' => 0, 'months' => 0, 'days' => 1]))->toBe('1 day')
        ->and(HrDate::formatSpan(null))->toBe('');
});

it('clamps Feb-29 when adding years and crosses months when adding days', function () {
    expect(HrDate::addYears('2024-02-29', 1))->toBe('2025-02-28')
        ->and(HrDate::addDays('2024-12-31', 1))->toBe('2025-01-01')
        ->and(HrDate::addDays('2024-03-01', -1))->toBe('2024-02-29');
});

it('counts calendar days in both directions', function () {
    expect(HrDate::daysBetween('2024-01-01', '2024-03-01'))->toBe(60)
        ->and(HrDate::daysBetween('2024-03-01', '2024-01-01'))->toBe(-60);
});

it('formats dates for display', function () {
    expect(HrDate::format('2024-03-05'))->toBe('Mar 5, 2024')
        ->and(HrDate::format(null))->toBe('')
        ->and(HrDate::yearOf('2024-03-05'))->toBe(2024);
});
