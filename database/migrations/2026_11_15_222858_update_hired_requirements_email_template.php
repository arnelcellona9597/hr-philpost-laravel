<?php

use App\Support\DefaultEmailTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_SUBJECT = 'Welcome to {{companyName}}: requirements for employment';

    private const OLD_BODY = <<<'TEXT'
Hi {{firstName}},

Congratulations! You have been hired as {{position}} at {{station}}.

To complete your employment, please submit the following requirements to the HR office:

{{requirementsList}}

If you have any questions, just reply to this email.

Thank you,
HR Office
TEXT;

    /**
     * Give the newly-hired email the formal, full list of requirements, unless HR already edited it.
     */
    public function up(): void
    {
        $new = collect(DefaultEmailTemplates::all())->firstWhere('key', 'hired-requirements');

        DB::table('email_templates')
            ->where('key', 'hired-requirements')
            ->where('subject', self::OLD_SUBJECT)
            ->where('body', self::OLD_BODY)
            ->update(['subject' => $new['subject'], 'body' => $new['body'], 'updated_at' => now()]);
    }

    /**
     * Put the previous wording back, unless HR edited the new one.
     */
    public function down(): void
    {
        $new = collect(DefaultEmailTemplates::all())->firstWhere('key', 'hired-requirements');

        DB::table('email_templates')
            ->where('key', 'hired-requirements')
            ->where('subject', $new['subject'])
            ->where('body', $new['body'])
            ->update(['subject' => self::OLD_SUBJECT, 'body' => self::OLD_BODY, 'updated_at' => now()]);
    }
};
