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
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->string('control_number', 30)->unique();
            $table->foreignId('template_id')->constrained('certificate_templates')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 200)->nullable();
            $table->date('issued_date');
            $table->string('template_name', 120);
            $table->string('employee_name')->index();
            $table->json('content');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('issued_by_name')->nullable();
            $table->string('status', 10)->default('Valid')->index();
            $table->string('void_reason', 300)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
