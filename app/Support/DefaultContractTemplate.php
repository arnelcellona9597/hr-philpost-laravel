<?php

namespace App\Support;

/**
 * The office's Contract of Service, created once on an empty install. Its clauses are the same for
 * everyone, and {{scopeOfService}} brings in the duties chosen when issuing. Officials and workers are
 * placeholders, so no real name is kept in the code.
 *
 * @phpstan-import-type Block from CertificateBlocks
 */
class DefaultContractTemplate
{
    /**
     * @return array{key: string, name: string, description: string, prefix: string, sort_order: int, paper_size: string, font: string, margin: string}
     */
    public static function definition(): array
    {
        return [
            'key' => 'contract-of-service',
            'name' => 'Contract of Service',
            'description' => 'The standard contract. The duties come from the Scope of Service chosen when issuing.',
            'prefix' => 'COS',
            'sort_order' => 10,
            'paper_size' => 'Folio',
            'font' => 'arial',
            'margin' => 'narrow',
        ];
    }

    /**
     * @return list<Block>
     */
    public static function blocks(): array
    {
        $text = fn (string $text, string $align = 'justify'): array => [...CertificateBlocks::newBlock('paragraph'), 'text' => $text, 'align' => $align, 'size' => 'xs', 'indent' => false];
        $space = fn (string $size = 'sm'): array => [...CertificateBlocks::newBlock('spacer'), 'size' => $size];

        return [
            $text("Republic of the Philippines\n**PHILIPPINE POSTAL CORPORATION**\nLiwasang Bonifacio, Manila", 'center'),
            [...CertificateBlocks::newBlock('title'), 'text' => 'CONTRACT OF SERVICE', 'size' => 'md'],
            $space(),
            $text('This Contract of Service entered into by and between:'),
            $text('The Philippine Postal Corporation, a Corporation created and existing under and by virtue of Republic Act No. 7354, with regional place of business at Area 7, Eastern Mindanao, Davao City, Philippines, duly represented herewith by {{areaDirectorTitle}}, **{{areaDirector}}**, hereinafter referred to as the “Corporation”'),
            $text('and', 'center'),
            $text('**{{fullNameUpper}}** of legal age, Filipino and with residence and postal address at {{address}}, hereinafter referred to as the “Service Contractor”;'),
            $text('**W I T N E S S E T H**', 'center'),
            $text('WHEREAS, the Corporation hired the Service Contractor as **{{position}}** for {{office}}, and that said Service Contractor is willing and ready to provide the service;'),
            $text('NOW, THEREFORE, in the interest of the service, the parties hereby agree to hire the contract of the above-named Service Contractor in accordance with the following terms and conditions:'),
            $text('**1. TERM** – The services of the Service Contractor shall commence on **{{termStart}}** up to **{{termEnd}}**, or unless sooner terminated upon the advice by either party.'),
            $text('**2. REMUNERATION** – For {{possessive}} services, the Service Contractor shall be paid a fixed daily wage of {{remuneration}} in Philippine Currency, plus other incentives as may be authorized by law or management. The premium payments will cover the worker’s contributions, classified as voluntary or self-employed membership, to government-mandated social security programs (SSS, PhilHealth, and the Pag-IBIG Fund) ensuring access to social insurance, housing and loan facility, and healthcare.'),
            $text("**3. ALLOWABLE PREMIUMS AND ANCILLARY BENEFITS (Per CSC–COA–DBM Joint Circular No. 1, s. 2025)** - In addition to his/her base compensation, the Service Contractor may be granted, at Management’s discretion and subject to availability of funds, approved guidelines, issuance of supporting documentation, and COA/CSC/DBM audit rules, the following premiums and ancillary benefits applicable to Administrative Support/Ancillary Administrative Services:\n3.1 Overtime Pay, solely in cases where the Service Contractor renders authorized work beyond regular working hours, subject to Management approval, budget availability, and in compliance with applicable audit and documentation requirements; and\n3.2 Travelling Expenses, when directly incurred in the performance of assigned duties and duly supported by approved travel authority and required documentation, subject to applicable accounting and auditing rules.\nFor avoidance of doubt, the foregoing does not constitute Plantilla or statutory benefits and shall not be interpreted as conferring civil service status or employer-employee relationship, in accordance with CSC–COA–DBM Joint Circular No. 1, s. 2025."),
            $text('**4. NON-ENTITLEMENT TO PLANTILLA BENEFITS** - This Contract shall not create an employer-employee relationship, and the Service Contractor shall not in any way be entitled to GSIS, PhilHealth, HDMF employer share, leave credit, RATA, PERA, bonuses, step increment, or any compensation or benefit accorded to regular Plantilla personnel.'),
            $text('**5. PLACE OF SERVICE** – the Service Contractor shall be under the direct control and supervision of the {{placeOfService}}, and shall render {{possessive}} services at {{office}}. The Service Contractor shall be provided with the appropriate facilities and administrative support for the effective discharge of {{possessive}} functions.'),
            $text("**6. SCOPE OF SERVICE** – The Service Contractor shall perform the following tasks:\n{{scopeOfService}}\nIn any case, the Service Contractor shall not be made to perform functions which are part of the job description of the corporation’s existing regular employees and he/she shall not be designated to positions exercising control of supervision over regular and career employees. (COA-DBM Joint Circular No. 2, s. 2020)"),
            $text("**7. WELLNESS LEAVE** - The Service Contractor shall be entitled to Five (5) Days Wellness Leave with Pay during the contract period, subject to the following conditions:\n7.1 That Wellness Leave is intended to promote the physical and mental well-being of the Service Contractor;\n7.2 That Wellness Leave shall be non-cumulative, non-monetizable, and not convertible to cash;\n7.3 That availment of Wellness Leave shall be subject to prior approval of the immediate supervisor, except in emergency health situations;\n7.4 That Paid Wellness Leave shall be charged against the appropriate MOOE account, subject to availability of funds and applicable COA rules;\n7.5 That the Grant of Wellness Leave shall not be construed as a Plantilla benefit, nor shall it create an employer-employee relationship, consistent with CSC-COA-DBM Joint Circular No.1, s. 2025 and applicable COA guidance; and\n7.6 That the grant of Wellness Leave to service contractors shall take effect only upon Board approval and the issuance of the PHLPost Internal Guidelines governing its implementation."),
            $text("**8. TERMINATION OF THE SERVICE OF THE SERVICE CONTRACTOR:**\n8.1 Dishonesty, fraud or willful breach of trust; loss of confidence;\n8.2 Gross Neglect of Duty;\n8.3 Grave Misconduct;\n8.4 Falsification of official Document;\n8.5 Physical or mental incapacity of disability;\n8.6 Disgraceful and immoral conduct;\n8.7 Frequent unauthorized absences, tardiness and undertime a total of 15 times in a month;\n8.8 Loafing during office hours;\n8.9 Gross insubordination;\n8.10 Gross discourtesy;\n8.11 Unsatisfactory performance rating for one (1) rating period;\n8.12 Redundancy of functions with that of the regular employees;\n8.13 Violation of the Confidentiality and Non-Disclosure Agreement provided hereof;\n8.14 Other analogous causes (like budget or funds availability)."),
            $text("**9. CONFIDENTIALITY AND NON-DISCLOSURE UNDERTAKING OF THE SERVICE CONTRACTOR:**\n9.1 The Service Contractor shall process (collect, record, organize, store, update or modify, retrieve, use, make available for consultation, consolidate, block, erase or destroy) the confidential and/or proprietary business information of or regarding the Company and personal information of its client, or personal information of the clients of its client that the Service Contractor obtained in the course of the term of his/her contract, only in accordance with established guidelines.\n9.2 The Service Contractor shall notify his/her immediate superior, immediately upon discovery of, or suspicion of any unauthorized use or disclosure of confidential information, including personal information within twenty-four (24) hours.\n9.3 Upon termination of the Service Contractor’s service contract, he/she shall return to the Philippine Postal Corporation all documents and property thereof, including but not necessarily limited to: reports, manuals, correspondence, computer programs, and all other materials and all copies thereof relating in any way to the corporation’s operation that was previously and clearly identified to be confidential, including client’s data that were obtained during the course of {{possessive}} Contract.\nThe Service Contractor further agrees that {{pronoun}} shall not retain copies, notes or abstracts of the foregoing."),
            $text("**10. MISCELLANEOUS**\n10.1 The Service Contractor is not allowed to enjoy the flexible working schedule since he/she is hired in the exigency of the service and to augment the required working hours rendered by the regular employees.\n10.2 The Service Contractor may attend or participate in development opportunities such as orientations in-house local trainings, capacity-building and the like.\n10.3 The Services rendered under this Contract shall not be considered government services and therefore not covered by Civil Service Law, Rules and Regulations.\n10.4 The Service Contractor agrees to attend and handle all matter conscientiously and with the best care, skill and ability and shall, at all times work to protect the interest of the Corporation, and in no case shall the Service Contractor assist or take part in any action against the Corporation after the expiration of this contract.\n10.5 The Service Contractor is required to submit accomplishment reports which shall be quantifiable as much as practicable which shall be the primary basis for the payment of daily wages wherein the DTR is considered merely a secondary requirement.\n10.6 The Service Contractor is required to submit TIN as one of the requirements before wages can be paid.\n10.7 In no case further shall the Service Contractor be engaged in employment and/or undertaking involving the same or related services or industry as the PHLPost, one (1) year after his/her separation (resignation, termination) and/or expiration of his/her service contract with PHLPost.\n10.8 This contract supersedes and replaces any and all previous contract/s for service entered into by and between the Corporation and the Service Contractor.\n10.9 In the event that the management approves the procurement of uniforms, the Service Contractor must be willing to be deducted a certain amount intended to pay for such uniform/s.\n10.10 The Service Contractor shall be cleared from money and/or property accountabilities once separated from the service.\n10.11 Should the Service Contractor decide to terminate this Agreement before its expiry date for any reason, he/she shall fill a written notice at least thirty (30) calendar days prior to actual date of termination. The PHLPOST may however, with or without cause, terminate this Agreement by giving seven (7) calendar days prior notice to the Service Contractor."),
            $text('**IN WITNESS WHEREOF**, the parties hereto have set their hands at _____________, Philippines, this ____day of ______________, {{termYear}}.'),
            $text('**PHILIPPINE POSTAL CORPORATION**', 'left'),
            [...CertificateBlocks::newBlock('signatory'), 'align' => 'left', 'signers' => [
                ['label' => 'By:', 'name' => '{{areaDirector}}', 'title' => '{{areaDirectorTitle}}'],
                ['label' => '', 'name' => '{{fullNameUpper}}', 'title' => 'Service Contractor'],
            ]],
            $text('Signed in the presence of:', 'left'),
            [...CertificateBlocks::newBlock('signatory'), 'align' => 'left', 'signers' => [
                ['label' => '', 'name' => '{{adfinChief}}', 'title' => '{{adfinChiefTitle}}'],
                ['label' => '', 'name' => '{{hrmChief}}', 'title' => '{{hrmChiefTitle}}'],
            ]],
            $text('Funds Available:', 'left'),
            [...CertificateBlocks::newBlock('signatory'), 'align' => 'left', 'signers' => [
                ['label' => '', 'name' => '{{budgetOfficer}}', 'title' => '{{budgetOfficerTitle}}'],
            ]],
            $space('md'),
            $text('**A C K N O W L E D G E M E N T**', 'center'),
            $text('BEFORE ME, a Notary Public for and in the City of Davao, personally came and appeared, **{{areaDirector}}**, in his capacity as {{areaDirectorTitle}} of PHLPost Area 7, Davao City, with Government-Issued ID___________ ID No.__________________ and **{{fullNameUpper}}** with Government-Issued ID ___________ID No. _____________, in {{possessive}} capacity as Service Contractor known to me to be the same persons who executed the foregoing instrument and they acknowledged to me that the same is their free and voluntary act and deed.'),
            $text('This instrument consisting of three (3) pages, including the page on which this acknowledgement is written, refers to the Contract of Service and duly signed by the parties and their instrumental witnesses on every page hereof.'),
            $text('IN WITNESS WHEREOF, I have hereunto set my hand and Notarial Seal this ____day of ______________, at__________________, Philippines.'),
            $text("Doc. No. _______\nPage No. _______\nBook No. _______\nSeries of {{termYear}}.", 'left'),
            [...CertificateBlocks::newBlock('pageFooter'), 'text' => "{{adfinChief}}\n{{adfinChiefTitle}} | {{fullNameTitle}}\nService Contractor | {{areaDirector}}\n{{areaDirectorTitle}} | {{hrmChief}}\n{{hrmChiefTitle}}"],
        ];
    }
}
