<?php

namespace App\Support;

use App\Models\User;

/**
 * Navigation model shared by the sidebar and the topbar breadcrumbs.
 *
 * @phpstan-type NavItem array{href: string, label: string, icon: string}
 * @phpstan-type NavGroup array{label: string, items: list<NavItem>}
 */
class Navigation
{
    /**
     * @return list<NavGroup>
     */
    public static function groups(?User $user): array
    {
        $isSystemAdmin = $user?->isSystemAdmin() ?? false;

        $administration = array_values(array_filter([
            $isSystemAdmin ? ['href' => '/users', 'label' => 'HR Accounts', 'icon' => 'shield'] : null,
            ['href' => '/settings', 'label' => 'Branding & Theme', 'icon' => 'settings'],
            ['href' => '/settings/notifications', 'label' => 'Email Settings', 'icon' => 'send'],
            $isSystemAdmin ? ['href' => '/settings/email-server', 'label' => 'Email Server (SMTP)', 'icon' => 'mail'] : null,
            ['href' => '/audit', 'label' => 'Audit Log', 'icon' => 'history'],
        ]));

        return [
            [
                'label' => 'Workspace',
                'items' => [
                    ['href' => '/', 'label' => 'Dashboard', 'icon' => 'dashboard'],
                    ['href' => '/employees', 'label' => 'Employees', 'icon' => 'users'],
                    ['href' => '/onboarding', 'label' => 'Onboarding Tracker', 'icon' => 'clipboard'],
                    ['href' => '/leave', 'label' => 'Wellness Leave', 'icon' => 'calendar'],
                    ['href' => '/branches', 'label' => 'Branches', 'icon' => 'building'],
                    ['href' => '/requirements', 'label' => 'Annual Requirements', 'icon' => 'file'],
                    ['href' => '/certificates', 'label' => 'Certificates', 'icon' => 'award'],
                    ['href' => '/contracts', 'label' => 'Contracts', 'icon' => 'briefcase'],
                    ['href' => '/payroll', 'label' => 'Payroll', 'icon' => 'wallet'],
                    ['href' => '/wage-orders', 'label' => 'Wage Orders', 'icon' => 'activity'],
                    ['href' => '/messages', 'label' => 'Messages', 'icon' => 'mail'],
                    ['href' => '/received-mails', 'label' => 'Received Mails', 'icon' => 'inbox'],
                    ['href' => '/remittances', 'label' => 'Remittances', 'icon' => 'landmark'],
                    ['href' => '/data', 'label' => 'Import / Export', 'icon' => 'sheet'],
                ],
            ],
            ['label' => 'Administration', 'items' => $administration],
        ];
    }

    /**
     * `/settings` only matches itself; every other item also owns its sub-pages.
     */
    public static function isActive(string $href, string $path): bool
    {
        if ($href === '/' || $href === '/settings') {
            return $path === $href;
        }

        return $path === $href || str_starts_with($path, "{$href}/");
    }

    /**
     * The nav item a page belongs to (longest matching prefix).
     *
     * @param  list<NavGroup>  $groups
     * @return NavItem|null
     */
    public static function sectionFor(string $path, array $groups): ?array
    {
        $best = null;

        foreach (array_merge(...array_column($groups, 'items')) as $item) {
            $owns = $item['href'] === '/' ? $path === '/' : ($path === $item['href'] || str_starts_with($path, "{$item['href']}/"));

            if ($owns && ($best === null || strlen($item['href']) > strlen($best['href']))) {
                $best = $item;
            }
        }

        return $best;
    }

    /**
     * Breadcrumbs: Home, the section from the navigation, then this page.
     *
     * @param  list<NavGroup>  $groups
     * @return list<array{href?: string, label: string}>
     */
    public static function crumbs(string $path, array $groups, string $title): array
    {
        if ($path === '/') {
            return [['label' => 'Dashboard']];
        }

        $section = self::sectionFor($path, $groups);
        $crumbs = [['href' => '/', 'label' => 'Home']];

        if ($section !== null && $section['href'] !== '/' && $section['href'] !== $path) {
            $crumbs[] = ['href' => $section['href'], 'label' => $section['label']];
        }

        $crumbs[] = ['label' => ($section['href'] ?? null) === $path ? $section['label'] : $title];

        return $crumbs;
    }
}
