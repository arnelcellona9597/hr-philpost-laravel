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
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->nullable()->unique();
            $table->string('name', 120)->unique();
            $table->string('description', 400)->nullable();
            $table->string('prefix', 8)->default('COS');
            $table->string('paper_size', 10)->default('A4');
            $table->string('font', 10)->default('serif');
            $table->string('margin', 10)->default('normal');
            $table->json('blocks');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('contract_settings', function (Blueprint $table) {
            $table->id();
            $table->string('area_director_name', 120)->nullable();
            $table->string('area_director_title', 160)->nullable();
            $table->string('adfin_chief_name', 120)->nullable();
            $table->string('adfin_chief_title', 160)->nullable();
            $table->string('hrm_chief_name', 120)->nullable();
            $table->string('hrm_chief_title', 160)->nullable();
            $table->string('budget_officer_name', 120)->nullable();
            $table->string('budget_officer_title', 160)->nullable();
            $table->string('default_place_of_service', 160)->nullable();
            $table->string('wage_order', 255)->nullable();
            $table->unsignedTinyInteger('premium_percent')->default(15);
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('control_number', 30)->unique();
            $table->foreignId('template_id')->nullable()->constrained('contract_templates')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('template_name', 120);
            $table->string('employee_name', 255)->index();
            $table->string('position', 120)->nullable();
            $table->string('office', 160)->nullable();
            $table->string('place_of_service', 160)->nullable();
            $table->string('address', 255)->nullable();
            $table->date('term_start')->index();
            $table->date('term_end')->index();
            $table->unsignedBigInteger('daily_wage_centavos')->default(0);
            $table->unsignedBigInteger('second_wage_centavos')->nullable();
            $table->date('second_wage_from')->nullable();
            $table->string('wage_order', 255)->nullable();
            $table->unsignedTinyInteger('premium_percent')->default(0);
            $table->json('content');
            $table->string('status', 10)->default('Valid')->index();
            $table->string('void_reason', 300)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('issued_by_name', 120)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'term_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('contract_settings');
        Schema::dropIfExists('contract_templates');
    }
};
