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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id', 40)->unique();
            $table->string('full_name')->index();
            $table->foreignId('profile_picture_id')->nullable()->constrained('media')->restrictOnDelete();
            $table->string('last_name', 80)->index();
            $table->string('first_name', 80)->index();
            $table->string('middle_name', 80)->nullable();
            $table->string('extension', 10)->nullable();
            $table->string('gender', 10)->index();
            $table->date('date_of_birth')->nullable();
            $table->string('civil_status', 20)->nullable()->index();
            $table->string('religion', 80)->nullable();
            $table->string('blood_type', 3)->nullable();
            $table->string('professional_eligibility', 200)->nullable();
            $table->string('contact_number', 50)->nullable();
            $table->string('email')->nullable()->index();
            $table->string('address', 300)->nullable();
            $table->string('educational_attainment', 40)->nullable()->index();
            $table->string('courses', 300)->nullable();
            $table->string('year_level_earned_units', 120)->nullable();
            $table->string('academic_honors', 300)->nullable();
            $table->string('classification', 20)->index();
            $table->string('employment_status', 20)->default('Active')->index();
            $table->foreignId('station_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('region', 20)->nullable()->index();
            $table->string('position', 150)->nullable()->index();
            $table->string('nature_of_work', 150)->nullable();
            $table->string('driver_license_number', 40)->nullable();
            $table->date('date_hired')->nullable()->index();
            $table->date('last_day_of_service')->nullable();
            $table->text('remarks')->nullable();
            $table->string('sss', 20)->nullable()->index();
            $table->string('pagibig', 20)->nullable()->index();
            $table->string('tin', 20)->nullable()->index();
            $table->string('philhealth', 20)->nullable()->index();
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_relationship', 60)->nullable();
            $table->string('emergency_contact_number', 50)->nullable();
            $table->string('emergency_address', 300)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
