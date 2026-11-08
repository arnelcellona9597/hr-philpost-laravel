<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A square icon for the browser tab and the collapsed sidebar, apart from the (often wide) logo.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->foreignId('favicon_id')->nullable()->after('logo_id')->constrained('media')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('favicon_id');
        });
    }
};
