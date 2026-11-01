<?php

namespace App\Support;

/**
 * The starter certificates, in the office's own wording. HR edits them from there. The signatory comes
 * from Officials and wages, so no real name is kept in the code.
 *
 * @phpstan-import-type Block from CertificateBlocks
 */
class DefaultCertificateTemplates
{
    private const CERTIFY = '**THIS IS TO CERTIFY** that **{{honorificUpper}} {{fullNameUpper}}**';

    private const OFFICE = 'the Philippine Postal Corporation, Area 7, Eastern Mindanao';

    private const RENEWABLE = '{{possessiveCap}} contract is renewable every six (6) months based on {{possessive}} performance.';

    /**
     * @return list<array{key: string, name: string, description: string, sort_order: int, font: string, blocks: list<Block>}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'coe-active',
                'name' => 'COE – Current Worker',
                'description' => 'For workers in service, for loans, bank accounts and other purposes. Shows the current wage.',
                'sort_order' => 10,
                'font' => 'sans',
                'blocks' => self::layout(self::CERTIFY.' has been hired as a {{engagementType}} of '.self::OFFICE.', and currently assigned as {{position}} under the {{station}}, effective **{{dateHired}} to present**{{compensationClause}}. '.self::RENEWABLE),
            ],
            [
                'key' => 'coe-newly-hired',
                'name' => 'COE – Newly Hired',
                'description' => 'For new workers who need proof of employment, such as for a payroll bank account.',
                'sort_order' => 20,
                'font' => 'sans',
                'blocks' => self::layout(self::CERTIFY.' has been hired as a {{engagementType}} of '.self::OFFICE.', employed as {{position}} under the {{station}}, effective **{{dateHired}}**{{compensationClause}}. '.self::RENEWABLE),
            ],
            [
                'key' => 'coe-separated',
                'name' => 'COE – Resigned / Separated',
                'description' => 'For former workers: position and period of engagement.',
                'sort_order' => 30,
                'font' => 'sans',
                'blocks' => self::layout(self::CERTIFY.' was engaged as a {{engagementType}} at '.self::OFFICE.', designated as **{{positionWithArticle}}** under the {{station}}, for the period **{{dateHired}}, to {{lastDayOfService}}**.'),
            ],
        ];
    }

    /**
     * @return list<Block>
     */
    private static function layout(string $body): array
    {
        return CertificateBlocks::sanitize([
            ['type' => 'letterhead'],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'title', 'text' => 'CERTIFICATION', 'size' => 'lg', 'align' => 'center', 'wide' => true],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'paragraph', 'text' => 'TO WHOM IT MAY CONCERN:', 'align' => 'left', 'size' => 'md', 'indent' => false],
            self::paragraph($body),
            self::paragraph('This Certification is issued upon the request of {{honorific}} {{lastNameTitle}} for **{{purpose}}**.'),
            self::paragraph('Issued this {{issuedDay}} day of {{issuedMonth}}, {{issuedYear}} at Davao City, Philippines.'),
            ['type' => 'spacer', 'size' => 'xl'],
            ['type' => 'signatory', 'align' => 'right', 'signers' => [['label' => '', 'name' => '{{hrmChief}}', 'title' => '{{hrmChiefTitle}}']]],
            ['type' => 'spacer', 'size' => 'lg'],
            ['type' => 'footer', 'text' => 'Control No. {{controlNumber}}', 'align' => 'left'],
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
