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
                'subject' => 'Welcome to {{companyName}}: requirements for employment',
                'body' => <<<'TEXT'
                    Hi {{firstName}},

                    Congratulations! You have been hired as {{position}} at {{station}}.

                    To complete your employment, please submit the following requirements to the HR office:

                    {{requirementsList}}

                    If you have any questions, just reply to this email.

                    Thank you,
                    HR Office
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
