<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Applicants who already have a Start OJT date get the same first day of service, so the new column
     * is not empty. It is a separate field from here on and can be changed on its own.
     */
    public function up(): void
    {
        DB::table('applications')->whereNull('fds_date')->whereNotNull('start_ojt')->update(['fds_date' => DB::raw('start_ojt')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
