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

                    Congratulations on your selection as {{position}} at {{station}}. To process your Contract of Service (COS) engagement, please submit the following documentary requirements to the HR office:

                    • Three (3) sets of notarized, computerized Personal Data Sheet (PDS), Revised CS Form 212 (2025), downloadable at csc.gov.ph
                    • Three (3) passport-size ID pictures with white background, attached to the PDS
                    • Three (3) copies of handwriting specimen
                    • Laboratory tests evaluated by a government physician, with a duly signed Medical Certificate: photocopy of vaccination card, CBC and blood typing, urinalysis, chest X-ray and drug test
                    • Police Clearance
                    • Documentary stamps (₱30.00), attached to the Medical Certificate
                    • Government-issued ID, to be presented with the PDS
                    • Landbank account details

                    BIR requirements, to be submitted within ten (10) days upon assumption of duty:
                    • TIN number or TIN verification
                    • Notarized Sworn Declaration (Annex B-2), two (2) original copies with documentary stamps

                    Attachments for your first salary:
                    • Assumption to Duty
                    • Notarized Sworn Declaration
                    • DTR print-out (biometrics), duly signed by your supervisor, with Accomplishment Report

                    Additional requirements for COS Letter Carriers only:
                    • Photocopy of updated and valid Professional Driver's License
                    • Photocopy of updated and valid OR/CR of your owned motorcycle

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
