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
        Schema::create('ipcr_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year')->index();
            $table->string('last_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('station')->nullable();
            $table->string('job_title')->nullable();
            $table->string('rating_period', 10);
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->decimal('rating', 4, 3)->nullable();
            $table->string('adjectival_rating', 20)->nullable();
            $table->date('date_submitted')->nullable();
            $table->date('date_received')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'year', 'rating_period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ipcr_ratings');
    }
};
