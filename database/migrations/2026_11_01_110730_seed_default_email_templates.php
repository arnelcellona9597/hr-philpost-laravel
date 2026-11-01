<?php

use App\Support\DefaultEmailTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $fresh = ! DB::table('email_templates')->exists();

        foreach (DefaultEmailTemplates::all() as $template) {
            $exists = DB::table('email_templates')
                ->where('name', $template['name'])
                ->when($template['key'], fn ($query, string $key) => $query->orWhere('key', $key))
                ->exists();

            if (! $exists && ($fresh || $template['key'] !== null)) {
                DB::table('email_templates')->insert([...$template, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('email_templates')->whereIn('name', array_column(DefaultEmailTemplates::all(), 'name'))->delete();
    }
};
