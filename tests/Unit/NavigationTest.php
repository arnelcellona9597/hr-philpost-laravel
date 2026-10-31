<?php

use App\Support\Navigation;

$groups = Navigation::groups(null);

it('keeps settings exact and lets other items own their sub-pages', function () {
    expect(Navigation::isActive('/', '/employees'))->toBeFalse()
        ->and(Navigation::isActive('/settings', '/settings/notifications'))->toBeFalse()
        ->and(Navigation::isActive('/employees', '/employees/5/edit'))->toBeTrue()
        ->and(Navigation::isActive('/employees', '/employees-old'))->toBeFalse();
});

it('finds the section by longest prefix', function () use ($groups) {
    expect(Navigation::sectionFor('/settings/notifications', $groups)['label'])->toBe('Email Settings')
        ->and(Navigation::sectionFor('/nowhere', $groups))->toBeNull();
});

it('builds breadcrumbs', function () use ($groups) {
    expect(Navigation::crumbs('/', $groups, 'Dashboard'))->toBe([['label' => 'Dashboard']])
        ->and(Navigation::crumbs('/employees', $groups, 'x'))->toBe([['href' => '/', 'label' => 'Home'], ['label' => 'Employees']])
        ->and(Navigation::crumbs('/employees/5', $groups, 'Juan'))->toBe([
            ['href' => '/', 'label' => 'Home'],
            ['href' => '/employees', 'label' => 'Employees'],
            ['label' => 'Juan'],
        ]);
});
