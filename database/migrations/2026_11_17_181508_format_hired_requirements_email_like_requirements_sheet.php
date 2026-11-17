<?php

use App\Support\DefaultEmailTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_SUBJECT = 'Documentary requirements for employment at {{companyName}}';

    private const OLD_BODY = <<<'TEXT'
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
TEXT;

    /**
     * Lay the newly-hired email out like the official requirements sheet, unless HR already edited it.
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
