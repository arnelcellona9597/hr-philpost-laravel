<?php

use App\Support\Deadlines;

function deadlineSettings(array $overrides = []): array
{
    return [
        'itr' => ['month' => 4, 'day' => 30, 'next_year' => false],
        'sworn' => ['month' => 4, 'day' => 30, 'next_year' => true],
        'pds' => ['month' => 2, 'day' => 29, 'next_year' => false],
        'ipcr_jan_jun' => ['month' => 7, 'day' => 15, 'next_year' => false],
        'ipcr_jul_dec' => ['month' => 1, 'day' => 15, 'next_year' => true],
        'overrides' => $overrides,
    ];
}

it('computes the usual deadline in the record year or the next', function () {
    expect(Deadlines::for(deadlineSettings(), 'itr-submissions', 2026))->toBe('2026-04-30')
        ->and(Deadlines::for(deadlineSettings(), 'sworn-declarations', 2026))->toBe('2027-04-30');
});

it('uses a separate deadline for each IPCR rating period', function () {
    expect(Deadlines::for(deadlineSettings(), 'ipcr-ratings', 2026, 'Jan-Jun'))->toBe('2026-07-15')
        ->and(Deadlines::for(deadlineSettings(), 'ipcr-ratings', 2026, 'Jul-Dec'))->toBe('2027-01-15');
});

it('clamps Feb-29 to Feb-28 in common years', function () {
    expect(Deadlines::for(deadlineSettings(), 'pds-submissions', 2026))->toBe('2026-02-28')
        ->and(Deadlines::for(deadlineSettings(), 'pds-submissions', 2028))->toBe('2028-02-29');
});

it('lets a year-specific override beat the usual deadline', function () {
    $settings = deadlineSettings([['requirement' => 'itr-submissions', 'period' => null, 'year' => 2026, 'deadline' => '2026-05-15']]);

    expect(Deadlines::for($settings, 'itr-submissions', 2026))->toBe('2026-05-15')
        ->and(Deadlines::source($settings, 'itr-submissions', 2026))->toBe('override')
        ->and(Deadlines::for($settings, 'itr-submissions', 2027))->toBe('2027-04-30')
        ->and(Deadlines::source($settings, 'itr-submissions', 2027))->toBe('usual');
});

it('matches IPCR overrides on the rating period', function () {
    $settings = deadlineSettings([['requirement' => 'ipcr-ratings', 'period' => 'Jan-Jun', 'year' => 2026, 'deadline' => '2026-08-01']]);

    expect(Deadlines::for($settings, 'ipcr-ratings', 2026, 'Jan-Jun'))->toBe('2026-08-01')
        ->and(Deadlines::for($settings, 'ipcr-ratings', 2026, 'Jul-Dec'))->toBe('2027-01-15');
});

it('has no deadline when none is configured', function () {
    expect(Deadlines::for([], 'itr-submissions', 2026))->toBeNull()
        ->and(Deadlines::source([], 'itr-submissions', 2026))->toBe('none');
});

it('validates month and day pairs', function () {
    expect(Deadlines::isValidMonthDay(2, 29))->toBeTrue()
        ->and(Deadlines::isValidMonthDay(2, 30))->toBeFalse()
        ->and(Deadlines::isValidMonthDay(13, 1))->toBeFalse();
});

it('derives timeliness', function (?string $submitted, ?string $deadline, string $today, string $status, string $label) {
    $result = Deadlines::timeliness($submitted, $deadline, $today);

    expect($result['status'])->toBe($status)
        ->and($result['label'])->toBe($label);
})->with([
    'on the day' => ['2026-04-30', '2026-04-30', '2026-06-01', 'on-time', 'On time'],
    'one day late' => ['2026-05-01', '2026-04-30', '2026-06-01', 'late', 'Late (1 day)'],
    'overdue' => [null, '2026-04-30', '2026-05-03', 'overdue', 'Overdue (3 days)'],
    'pending on the deadline' => [null, '2026-04-30', '2026-04-30', 'pending', 'Pending'],
    'submitted without a deadline' => ['2026-05-01', null, '2026-06-01', 'submitted', 'Submitted'],
    'nothing yet' => [null, null, '2026-06-01', 'pending', 'Pending'],
]);
