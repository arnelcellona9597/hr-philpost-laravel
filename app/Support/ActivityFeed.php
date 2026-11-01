<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Carbon;

/**
 * Turns audit entries into short sentences and links for the dashboard and the audit log.
 */
class ActivityFeed
{
    /**
     * Label and record page for each area. `{id}` is replaced with the record's id.
     */
    private const AREAS = [
        'employees' => ['employee', '/employees/{id}'],
        'employment-periods' => ['employment period', null],
        'branches' => ['branch', '/branches/{id}'],
        'applications' => ['applicant', '/onboarding/{id}'],
        'wellness-leaves' => ['wellness leave', '/leave/{id}'],
        'itr-submissions' => ['ITR record', '/requirements/itr/{id}'],
        'sworn-declarations' => ['Sworn Declaration record', '/requirements/sworn-declaration/{id}'],
        'pds-submissions' => ['PDS record', '/requirements/pds/{id}'],
        'ipcr-ratings' => ['IPCR record', '/requirements/ipcr/{id}'],
        'payroll-periods' => ['payroll period', '/payroll/{id}'],
        'payslips' => ['payslip', '/payroll/payslips/{id}'],
        'email-templates' => ['email template', '/settings/templates/{id}'],
        'certificates' => ['certificate', '/certificates/{id}'],
        'certificate-templates' => ['certificate template', '/certificates/templates/{id}'],
        'holidays' => ['holiday', '/leave/settings'],
        'received-mails' => ['received mail', '/received-mails/{id}/edit'],
        'users' => ['HR account', '/users'],
        'site-settings' => ['branding settings', '/settings'],
        'leave-settings' => ['leave settings', '/leave/settings'],
        'requirement-deadlines' => ['requirement deadlines', '/requirements/deadlines'],
        'notification-settings' => ['email settings', '/settings/notifications'],
        'smtp-settings' => ['email server settings', '/settings/email-server'],
        'payslip-settings' => ['payslip template', '/payroll/settings'],
        'import' => ['Excel import', '/data'],
    ];

    private const VERBS = ['create' => 'added', 'update' => 'updated', 'delete' => 'deleted', 'import' => 'imported'];

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::AREAS);
    }

    /**
     * The record's page, except for deleted records, which no longer have one.
     */
    public static function link(AuditLog $log): ?string
    {
        $path = self::AREAS[$log->collection_slug][1] ?? null;

        if ($path === null || $log->action === 'delete' || (str_contains($path, '{id}') && $log->doc_id === null)) {
            return null;
        }

        return str_replace('{id}', (string) $log->doc_id, $path);
    }

    /**
     * @return array{who: string, verb: string, what: string, record: string|null, href: string|null, action: string, at: Carbon|null}
     */
    public static function describe(AuditLog $log): array
    {
        return [
            'who' => $log->user_name ?: 'System',
            'verb' => self::VERBS[$log->action] ?? $log->action,
            'what' => self::AREAS[$log->collection_slug][0] ?? str_replace('-', ' ', $log->collection_slug),
            'record' => $log->doc_label,
            'href' => self::link($log),
            'action' => $log->action,
            'at' => $log->created_at,
        ];
    }
}
