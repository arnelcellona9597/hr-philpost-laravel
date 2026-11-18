<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Semi-monthly COS payroll: each period is one half of a month (1-15 or 16-last day), at most once,
     * with its own review and approval. Each payslip keeps the rate source and wage order it was paid
     * under, so history shows the rate that applied at the time.
     */
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->nullable()->after('code');
            $table->unsignedTinyInteger('month')->nullable()->after('year');
            $table->unsignedTinyInteger('half')->nullable()->after('month');
            $table->timestamp('reviewed_at')->nullable()->after('status');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('reviewed_by');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->unique(['year', 'month', 'half']);
        });

        Schema::table('payslips', function (Blueprint $table): void {
            $table->string('rate_source', 10)->nullable()->after('premium_percent');
            $table->foreignId('wage_order_id')->nullable()->after('rate_source')->constrained()->nullOnDelete();
            $table->string('region', 20)->nullable()->after('wage_order_id');
            $table->unsignedBigInteger('regional_minimum_centavos')->nullable()->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('wage_order_id');
            $table->dropColumn(['rate_source', 'region', 'regional_minimum_centavos']);
        });

        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->dropUnique(['year', 'month', 'half']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['year', 'month', 'half', 'reviewed_at', 'approved_at']);
        });
    }
};
