<?php

namespace App\Support;

/**
 * The starter Contract of Service, created once on an empty install. It uses placeholders only: the
 * scope of service and the clauses are edited by HR, and duplicated for each kind of worker.
 *
 * @phpstan-import-type Block from CertificateBlocks
 */
class DefaultContractTemplate
{
    /**
     * @return array{key: string, name: string, description: string, prefix: string, sort_order: int}
     */
    public static function definition(): array
    {
        return [
            'key' => 'contract-of-service',
            'name' => 'Contract of Service',
            'description' => 'The standard contract for contract of service workers. Duplicate it for each scope of service.',
            'prefix' => 'COS',
            'sort_order' => 10,
        ];
    }

    /**
     * @return list<Block>
     */
    public static function blocks(): array
    {
        $paragraph = fn (string $text, string $align = 'justify', bool $indent = false): array => [...CertificateBlocks::newBlock('paragraph'), 'text' => $text, 'align' => $align, 'indent' => $indent];
        $space = fn (string $size = 'sm'): array => [...CertificateBlocks::newBlock('spacer'), 'size' => $size];

        return [
            [...CertificateBlocks::newBlock('header'), 'lines' => ''],
            CertificateBlocks::newBlock('divider'),
            $space('md'),
            [...CertificateBlocks::newBlock('title'), 'text' => 'CONTRACT OF SERVICE', 'size' => 'md'],
            $space('md'),
            $paragraph('**KNOW ALL MEN BY THESE PRESENTS:**', 'left'),
            $paragraph('This Contract of Service is made and entered into on the {{issuedDay}} day of {{issuedMonthYear}} at {{placeOfService}}, by and between:', 'justify', true),
            $paragraph('**{{companyName}}**, represented by its {{areaDirectorTitle}}, **{{areaDirector}}**, hereinafter referred to as the **FIRST PARTY**; and', 'justify', true),
            $paragraph('**{{fullNameUpper}}**, of legal age, Filipino, with address at {{address}}, hereinafter referred to as the **SECOND PARTY**.', 'justify', true),
            $paragraph('**WITNESSETH:** That the FIRST PARTY needs the services of a person for work that is temporary and not part of the regular plantilla, and the SECOND PARTY is willing and qualified to render such services under the following terms and conditions:'),
            $paragraph('**1. ENGAGEMENT.** The FIRST PARTY engages the SECOND PARTY as **{{position}}** at {{office}}, to render service at {{placeOfService}}.'),
            $paragraph("**2. SCOPE OF SERVICE.** The SECOND PARTY shall:\n2.1 Perform the duties of {{position}} as assigned by the FIRST PARTY;\n2.2 Observe the working hours and office policies of the FIRST PARTY;\n2.3 Perform other related tasks that may be assigned from time to time."),
            $paragraph('**3. TERM.** This contract takes effect on **{{termStart}}** and ends on **{{termEnd}}**, unless sooner terminated by either party with notice.'),
            $paragraph('**4. REMUNERATION.** The FIRST PARTY shall pay the SECOND PARTY a daily wage of {{remuneration}}, for the days of service actually rendered, subject to the usual government deductions and the availability of funds.'),
            $paragraph('**5. NO EMPLOYER-EMPLOYEE RELATIONSHIP.** This contract does not create an employer-employee relationship between the parties, and the services rendered are not service credited to the government.'),
            $paragraph('**6. TERMINATION.** Either party may terminate this contract by giving written notice, without prejudice to the payment of services already rendered.'),
            $paragraph('**IN WITNESS WHEREOF**, the parties have signed this contract on the date and at the place first written above.', 'justify', true),
            $space('md'),
            [...CertificateBlocks::newBlock('signatory'), 'align' => 'left', 'signers' => [
                ['label' => 'FIRST PARTY:', 'name' => '{{areaDirector}}', 'title' => '{{areaDirectorTitle}}'],
                ['label' => 'SECOND PARTY:', 'name' => '{{fullNameUpper}}', 'title' => '{{position}}'],
            ]],
            $space('md'),
            [...CertificateBlocks::newBlock('signatory'), 'align' => 'left', 'signers' => [
                ['label' => 'Certified funds available:', 'name' => '{{budgetOfficer}}', 'title' => '{{budgetOfficerTitle}}'],
                ['label' => 'Recommending approval:', 'name' => '{{adfinChief}}', 'title' => '{{adfinChiefTitle}}'],
                ['label' => 'Reviewed by:', 'name' => '{{hrmChief}}', 'title' => '{{hrmChiefTitle}}'],
            ]],
            CertificateBlocks::newBlock('pageBreak'),
            [...CertificateBlocks::newBlock('title'), 'text' => 'ACKNOWLEDGEMENT', 'size' => 'md'],
            $space('md'),
            $paragraph('Before me, a notary public, personally appeared **{{fullNameUpper}}** and the representative of the FIRST PARTY, known to me to be the persons who executed the foregoing contract, and acknowledged that it is their free and voluntary act and deed.', 'justify', true),
            $paragraph('Witness my hand and seal on {{issuedDate}} at {{placeOfService}}.', 'justify', true),
            [...CertificateBlocks::newBlock('pageFooter'), 'text' => 'Initials: {{areaDirectorInitials}}  {{adfinChiefInitials}}  {{hrmChiefInitials}}  {{budgetOfficerInitials}}'],
        ];
    }
}
