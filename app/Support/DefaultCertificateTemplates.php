<?php

namespace App\Support;

/**
 * The starter certificates, created once on an empty install. HR edits them from there.
 *
 * @phpstan-import-type Block from CertificateBlocks
 */
class DefaultCertificateTemplates
{
    private const REQUEST = 'This certification is issued upon the request of {{honorific}} {{lastName}} for {{purpose}}.';

    /**
     * @return list<array{key: string, name: string, description: string, sort_order: int, blocks: list<Block>}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'coe-active',
                'name' => 'Certificate of Employment',
                'description' => 'For current employees: loans, bank, visa, scholarship and other general purposes.',
                'sort_order' => 10,
                'blocks' => self::layout('This is to certify that **{{honorific}} {{fullNameUpper}}** is a {{classification}} employee of {{companyName}}, presently holding the position of **{{position}}** at {{station}}. {{pronounCap}} has been with the office since {{dateHired}} up to the present.'),
            ],
            [
                'key' => 'coe-newly-hired',
                'name' => 'Certificate of Employment – Newly Hired',
                'description' => 'For new employees who need proof of employment (e.g. payroll account, government IDs).',
                'sort_order' => 20,
                'blocks' => self::layout('This is to certify that **{{honorific}} {{fullNameUpper}}** has been hired by {{companyName}} as **{{position}}** ({{classification}}) assigned at {{station}}, effective {{dateHired}}.'),
            ],
            [
                'key' => 'coe-separated',
                'name' => 'Certificate of Employment – Resigned / Separated',
                'description' => 'For former employees (resigned, retired or terminated): dates and length of service.',
                'sort_order' => 30,
                'blocks' => self::layout('This is to certify that **{{honorific}} {{fullNameUpper}}** was employed by {{companyName}} as **{{position}}** ({{classification}}) at {{station}} from {{dateHired}} until {{pronoun}} {{separation}} on {{lastDayOfService}}, a total service of {{lengthOfService}}.'),
            ],
        ];
    }

    /**
     * @return list<Block>
     */
    private static function layout(string $body): array
    {
        return CertificateBlocks::sanitize([
            ['type' => 'header', 'showLogo' => true, 'companyName' => '', 'lines' => '', 'align' => 'center'],
            ['type' => 'divider'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'title', 'text' => 'CERTIFICATE OF EMPLOYMENT', 'size' => 'lg', 'align' => 'center', 'wide' => true],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'paragraph', 'text' => '**TO WHOM IT MAY CONCERN:**', 'align' => 'left', 'size' => 'md', 'indent' => false],
            self::paragraph($body),
            self::paragraph(self::REQUEST),
            self::paragraph('Issued this {{issuedDay}} day of {{issuedMonthYear}}.'),
            ['type' => 'spacer', 'size' => 'xl'],
            ['type' => 'signatory', 'align' => 'right', 'signers' => [['label' => '', 'name' => '', 'title' => 'Human Resource Officer']]],
            ['type' => 'spacer', 'size' => 'xl'],
            ['type' => 'footer', 'text' => 'Control No. {{controlNumber}} · Not valid without the official dry seal.', 'align' => 'left'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'text' => $text, 'align' => 'justify', 'size' => 'md', 'indent' => true];
    }
}
