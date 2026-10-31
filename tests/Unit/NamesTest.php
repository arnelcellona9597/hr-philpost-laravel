<?php

use App\Support\Names;

it('builds the HR listing name', function () {
    expect(Names::lastFirst('DELA CRUZ', 'Juan', 'pedro', 'Jr.'))->toBe('DELA CRUZ, Juan P. Jr.')
        ->and(Names::lastFirst('Reyes', 'Ana'))->toBe('Reyes, Ana')
        ->and(Names::lastFirst('  De   Leon ', ' Ana '))->toBe('De Leon, Ana');
});

it('builds the first-last name', function () {
    expect(Names::firstLast('Dela Cruz', 'Juan', 'Pedro', 'Jr.'))->toBe('Juan P. Dela Cruz Jr.');
});

it('takes up to two initials', function () {
    expect(Names::initials('Juan Dela Cruz'))->toBe('JD')
        ->and(Names::initials('ñino'))->toBe('Ñ')
        ->and(Names::initials(null))->toBe('?');
});
