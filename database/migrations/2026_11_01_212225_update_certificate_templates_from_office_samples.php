<?php

use App\Support\DefaultCertificateTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The starter templates take the office's own wording. Issued certificates keep the text they have.
     */
    public function up(): void
    {
        foreach (DefaultCertificateTemplates::all() as $template) {
            DB::table('certificate_templates')->where('key', $template['key'])->update([
                'name' => $template['name'],
                'description' => $template['description'],
                'font' => $template['font'],
                'blocks' => json_encode($template['blocks'], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
