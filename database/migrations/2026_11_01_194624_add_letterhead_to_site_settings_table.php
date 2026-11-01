<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('letterhead_top', 160)->nullable();
            $table->string('letterhead_name', 400)->nullable();
            $table->string('letterhead_details', 600)->nullable();
            $table->string('letterhead_section', 160)->nullable();
            $table->foreignId('letterhead_logo_id')->nullable()->constrained('media')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('letterhead_logo_id');
            $table->dropColumn(['letterhead_top', 'letterhead_name', 'letterhead_details', 'letterhead_section']);
        });
    }
};
