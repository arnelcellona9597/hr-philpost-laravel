<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Templates on the old "Serif" choice move to Times New Roman, which is how they showed on screen
     * and in Word. Issued documents keep the font they were issued with.
     */
    public function up(): void
    {
        foreach (['certificate_templates', 'contract_templates'] as $table) {
            DB::table($table)->where('font', 'serif')->update(['font' => 'times']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['certificate_templates', 'contract_templates'] as $table) {
            DB::table($table)->where('font', 'times')->update(['font' => 'serif']);
        }
    }
};
