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
        Schema::create('payslip_settings', function (Blueprint $table) {
            $table->id();
            $table->string('title', 60)->default('PAYSLIP');
            $table->string('company_name')->nullable();
            $table->text('address_lines')->nullable();
            $table->boolean('show_logo')->default(true);
            $table->string('currency_symbol', 5)->default('₱');
            $table->json('earning_items');
            $table->json('deduction_items');
            $table->json('shown_fields');
            $table->string('prepared_by_name')->nullable();
            $table->string('prepared_by_title')->nullable();
            $table->string('certified_by_name')->nullable();
            $table->string('certified_by_title')->nullable();
            $table->text('footer_note')->nullable();
            $table->string('paper_size', 15)->default('A4');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payslip_settings');
    }
};
