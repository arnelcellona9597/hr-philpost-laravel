<?php

namespace App\Support;

use App\Models\NotificationSetting;
use App\Models\SiteSetting;

/**
 * The branded look of every email. Bodies are plain text written by HR and are escaped here.
 */
class EmailLayout
{
    public static function html(string $subject, string $body, ?string $senderName = null): string
    {
        $site = SiteSetting::current();
        $primary = Colors::resolve($site->palette)['primary'];

        return view('mail.branded', [
            'subject' => $subject,
            'bodyHtml' => MergeTemplate::textToHtml($body),
            'companyName' => $site->company_name,
            'primary' => $primary,
            'onPrimary' => Colors::readableOn($primary),
            'logoUrl' => $site->logo_id ? url("/files/{$site->logo_id}") : null,
            'footerLines' => self::footerLines($senderName),
        ])->render();
    }

    public static function text(string $subject, string $body, ?string $senderName = null): string
    {
        return $subject."\n\n".trim($body)."\n\n--\n".implode("\n", self::footerLines($senderName));
    }

    /**
     * @return list<string>
     */
    private static function footerLines(?string $senderName): array
    {
        $company = SiteSetting::current()->company_name;

        return array_values(array_filter([
            NotificationSetting::current()->footer,
            $senderName ? "Sent by {$senderName} · {$company}" : $company,
        ]));
    }
}
