<?php

namespace App\Support;

/**
 * The templates behind automatic emails, plus two examples for a fresh install. HR edits the wording.
 */
class DefaultEmailTemplates
{
    /**
     * @return list<array{key: string|null, name: string, category: string, subject: string, body: string}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'leave-filed',
                'name' => 'Wellness leave filed',
                'category' => 'Leave',
                'subject' => 'Wellness leave recorded: {{leaveDates}}',
                'body' => <<<'TEXT'
                    Hi {{firstName}},

                    Your wellness leave has been recorded.

                    Dates: {{leaveDates}}
                    Working days: {{leaveDays}}
                    Status: {{leaveStatus}}
                    Wellness leave days left this year: {{leaveRemaining}}

                    If anything here is wrong, please contact the HR office.
                    TEXT,
            ],
            [
                'key' => 'leave-status',
                'name' => 'Wellness leave status changed',
                'category' => 'Leave',
                'subject' => 'Your wellness leave is now {{leaveStatus}}',
                'body' => <<<'TEXT'
                    Hi {{firstName}},

                    The status of your wellness leave on {{leaveDates}} ({{leaveDays}} working day/s) is now: {{leaveStatus}}.

                    Wellness leave days left this year: {{leaveRemaining}}
                    TEXT,
            ],
            [
                'key' => 'payroll-released',
                'name' => 'Payroll released (payslip)',
                'category' => 'Payroll',
                'subject' => 'Your payslip for {{payPeriod}}',
                'body' => <<<'TEXT'
                    Hi {{firstName}},

                    Payroll for {{payPeriod}} has been released (pay date: {{payDate}}).

                    Net pay: {{netPay}}

                    Your payslip is attached as a PDF. Please keep it for your records and contact the HR office if you see any discrepancy.
                    TEXT,
            ],
            [
                'key' => 'hired-requirements',
                'name' => 'Requirements for employment (newly hired)',
                'category' => 'Onboarding',
                'subject' => 'Documentary requirements for employment at {{companyName}}',
                'body' => <<<'TEXT'
                    Dear {{firstName}},

                    Congratulations on your selection as {{position}} at {{station}}. To process your Contract of Service (COS) engagement, please submit the following requirements to the HR office:

                    REQUIREMENTS FOR COS EMPLOYMENT

                    1. 3 Sets of Computerized Personal Data Sheet (PDS) - Notarized
                       Revised Form 2025 CS Form 212, downloadable at csc.gov.ph
                    2. 3 pieces passport-size ID picture in white background, attached to the PDS
                    3. Handwriting specimen, 3 copies (attached)
                    4. Lab Test evaluated by Government Physician and duly signed Medical Certificate
                       - Photocopy of Vaccination Card
                       - CBC and Blood Typing
                       - Urinalysis
                       - Chest X-ray
                       - Drug Test
                    5. Police Clearance
                    6. Documentary stamps (attach to Medical Certificate - ₱30.00 doc stamps)
                    7. BIR Requirements - to be submitted within 10 days upon assumption
                       - TIN Number or TIN Verification
                       - Notarized Sworn Declaration Form Annex B-2 (submit 2 original copies with doc stamps)
                    8. Government-issued ID, to be presented with the PDS
                       Attachments for 1st Salary:
                       - Assumption to Duty
                       - Sworn Declaration (Notarized)
                       - DTR print-out (Biometrics), duly signed by Supervisor/Management, with Accomplishment Report
                    9. Landbank Account Details

                    ADDITIONAL REQUIREMENTS FOR COS LETTER CARRIER

                    1. Photocopy of updated/valid Professional Driver's License
                    2. Photocopy of updated/valid OR/CR of owned Motorcycle

                    Our records show that the following are still outstanding:
                    {{requirementsList}}

                    Should you have any questions, please reply to this email.

                    Respectfully,
                    HR Office
                    {{companyName}}
                    TEXT,
            ],
            [
                'key' => 'requirement-reminder',
                'name' => 'Annual requirement reminder',
                'category' => 'Reminder',
                'subject' => 'Reminder: please submit your {{requirement}} for {{year}}',
                'body' => <<<'TEXT'
                    Hi {{firstName}},

                    Our records show that your {{requirement}} for {{year}} has not been submitted yet. Please submit it to the HR office as soon as possible.

                    Thank you.
                    TEXT,
            ],
            [
                'key' => null,
                'name' => 'General announcement',
                'category' => 'Announcement',
                'subject' => 'Announcement from {{companyName}}',
                'body' => <<<'TEXT'
                    Hi {{firstName}},

                    [Write your announcement here.]

                    Thank you,
                    HR Office
                    TEXT,
            ],
            [
                'key' => null,
                'name' => 'Payroll schedule notice',
                'category' => 'Payroll',
                'subject' => 'Payroll schedule update',
                'body' => <<<'TEXT'
                    Hi {{firstName}},

                    Please be informed that [details of the payroll schedule change].

                    Thank you,
                    HR Office
                    TEXT,
            ],
        ];
    }
}
