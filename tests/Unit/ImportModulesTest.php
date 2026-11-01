<?php

use App\Support\ImportModules;

it('lists modules in import order', function () {
    expect(array_column(ImportModules::all(), 'slug'))->toBe([
        'branches',
        'holidays',
        'employees',
        'employment-history',
        'wage-orders',
        'pay-history',
        'position-history',
        'applications',
        'wellness-leaves',
        'itr-submissions',
        'sworn-declarations',
        'pds-submissions',
        'ipcr-ratings',
    ]);
});

it('keeps Excel sheet names within the 31 character limit', function () {
    foreach (ImportModules::all() as $module) {
        expect(strlen($module['sheet']))->toBeLessThanOrEqual(31);
    }
});

it('never repeats an Excel column in a module', function () {
    foreach (ImportModules::all() as $module) {
        $labels = array_column($module['fields'], 'label');

        expect($labels)->toBe(array_values(array_unique($labels)));
    }
});

it('finds a module by slug and reports unknown ones', function () {
    expect(ImportModules::find('ipcr-ratings')['key'])->toBe(['employee_id', 'year', 'rating_period'])
        ->and(ImportModules::find('nope'))->toBeNull();
});

it('takes select options from the enums', function () {
    $status = collect(ImportModules::find('wellness-leaves')['fields'])->firstWhere('name', 'status');

    expect($status['options'])->toBe(['Approved', 'Pending', 'Disapproved', 'Cancelled']);
});

it('maps every compliance route to a module', function () {
    foreach (ImportModules::COMPLIANCE_ROUTES as $slug) {
        expect(ImportModules::find($slug))->not->toBeNull();
    }
});
