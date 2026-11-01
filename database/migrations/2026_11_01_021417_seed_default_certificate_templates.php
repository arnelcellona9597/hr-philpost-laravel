<?php

use App\Support\DefaultCertificateTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('certificate_templates')->exists()) {
            return;
        }

        foreach (DefaultCertificateTemplates::all() as $template) {
            DB::table('certificate_templates')->insert([
                ...$template,
                'blocks' => json_encode($template['blocks'], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('certificate_templates')
            ->whereIn('key', array_column(DefaultCertificateTemplates::all(), 'key'))
            ->whereNotExists(fn ($query) => $query->from('certificates')->whereColumn('certificates.template_id', 'certificate_templates.id'))
            ->delete();
    }
};
