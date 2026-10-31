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
        Schema::create('wellness_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('last_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->date('date_filing')->index();
            $table->date('inclusive_date_from')->index();
            $table->date('inclusive_date_to')->index();
            $table->date('date_received')->nullable();
            $table->string('status', 15)->default('Approved')->index();
            $table->unsignedSmallInteger('days')->default(0);
            $table->unsignedSmallInteger('year')->index();
            $table->json('days_by_year')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wellness_leaves');
    }
};
