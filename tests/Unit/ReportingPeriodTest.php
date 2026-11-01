<?php

use App\Support\ReportingPeriod;

it('compares this year with the same days last year', function () {
    expect(ReportingPeriod::resolve('this-year', '2026-11-15'))->toMatchArray([
        'from' => '2026-01-01',
        'to' => '2026-11-15',
        'prevFrom' => '2025-01-01',
        'prevTo' => '2025-11-15',
        'bucket' => 'month',
    ]);
});

it('compares last month with the month before', function () {
    expect(ReportingPeriod::resolve('last-month', '2026-03-31'))->toMatchArray([
        'from' => '2026-02-01',
        'to' => '2026-02-28',
        'prevFrom' => '2026-01-01',
        'prevTo' => '2026-01-31',
    ]);
});

it('falls back to this year for an unknown key', function () {
    expect(ReportingPeriod::resolve('nope', '2026-11-15')['key'])->toBe('this-year');
});

it('adds months and clamps the day', function (string $from, int $months, string $expected) {
    expect(ReportingPeriod::addMonths($from, $months))->toBe($expected);
})->with([
    ['2026-03-31', -1, '2026-02-28'],
    ['2024-03-31', -1, '2024-02-29'],
    ['2026-01-15', -1, '2025-12-15'],
    ['2026-12-15', 1, '2027-01-15'],
    ['2026-01-15', -13, '2024-12-15'],
]);

it('groups weeks from Monday and labels buckets', function () {
    expect(ReportingPeriod::bucketKey('2026-11-15', 'week'))->toBe('2026-11-09')
        ->and(array_column(ReportingPeriod::buckets('2026-01-01', '2026-03-10', 'month'), 'label'))->toBe(['Jan', 'Feb', 'Mar'])
        ->and(array_column(ReportingPeriod::buckets('2025-12-01', '2026-01-10', 'month'), 'label'))->toBe(['Dec ’25', 'Jan ’26'])
        ->and(array_column(ReportingPeriod::buckets('2025-01-01', '2026-01-10', 'year'), 'label'))->toBe(['2025', '2026']);
});

it('has no percentage change without an earlier value', function () {
    expect(ReportingPeriod::percentChange(5, 0))->toBeNull()
        ->and(ReportingPeriod::percentChange(15, 10))->toEqual(50.0);
});
