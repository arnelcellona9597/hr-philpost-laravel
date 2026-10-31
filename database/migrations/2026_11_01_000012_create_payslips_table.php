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
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('payroll_periods')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('full_name');
            $table->string('employee_code', 40);
            $table->string('position', 150)->nullable();
            $table->string('station')->nullable();
            $table->string('classification', 20)->nullable();
            $table->string('tin', 20)->nullable();
            $table->string('sss', 20)->nullable();
            $table->string('philhealth', 20)->nullable();
            $table->string('pagibig', 20)->nullable();
            $table->json('earnings');
            $table->json('deductions');
            $table->unsignedBigInteger('gross_pay_centavos')->default(0);
            $table->unsignedBigInteger('total_deductions_centavos')->default(0);
            $table->unsignedBigInteger('net_pay_centavos')->default(0);
            $table->text('remarks')->nullable();
            $table->boolean('corrected_after_release')->default(false);
            $table->timestamps();
            $table->unique(['period_id', 'employee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
