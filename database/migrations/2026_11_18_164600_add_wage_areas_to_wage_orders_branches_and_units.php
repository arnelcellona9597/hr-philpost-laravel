<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimum wage areas inside a region, such as Cotabato City and the Maguindanao provinces in BARMM.
     * A wage order may cover one area instead of the whole region (blank area); each branch and each
     * station/unit can be placed in an area, the unit's area winning over its branch's. Payslips keep
     * the area they were paid under.
     */
    public function up(): void
    {
        Schema::table('wage_orders', function (Blueprint $table): void {
            $table->string('area', 60)->default('')->after('region');
            $table->dropUnique(['region', 'sector', 'effective_on']);
            $table->unique(['region', 'area', 'sector', 'effective_on']);
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->string('wage_area', 60)->nullable()->after('region');
        });

        Schema::table('branch_units', function (Blueprint $table): void {
            $table->string('wage_area', 60)->nullable()->after('name');
        });

        Schema::table('payslips', function (Blueprint $table): void {
            $table->string('wage_area', 60)->nullable()->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table): void {
            $table->dropColumn('wage_area');
        });

        Schema::table('branch_units', function (Blueprint $table): void {
            $table->dropColumn('wage_area');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('wage_area');
        });

        Schema::table('wage_orders', function (Blueprint $table): void {
            $table->dropUnique(['region', 'area', 'sector', 'effective_on']);
            $table->unique(['region', 'sector', 'effective_on']);
            $table->dropColumn('area');
        });
    }
};
