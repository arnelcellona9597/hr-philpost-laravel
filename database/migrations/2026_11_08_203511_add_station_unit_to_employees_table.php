<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The station, division or unit inside a branch, as the office writes it. The branch stays a separate choice.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('station_unit', 255)->nullable()->after('station_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('station_unit');
        });
    }
};
