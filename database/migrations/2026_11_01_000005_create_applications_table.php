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
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_id', 40)->unique();
            $table->string('applicant_name', 200)->index();
            $table->string('email', 254)->nullable()->index();
            $table->string('position_applied', 150)->index();
            $table->foreignId('station_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('replacement', 200)->nullable();
            $table->date('date_applied')->index();
            $table->date('start_ojt')->nullable();
            $table->string('application_status', 20)->default('Pending')->index();
            $table->string('req_pds', 10)->default('Pending');
            $table->string('req_specimen_sign', 10)->default('Pending');
            $table->string('req_sworn_declaration', 10)->default('Pending');
            $table->string('req_tin_verification', 10)->default('Pending');
            $table->string('req_police_clearance', 10)->default('Pending');
            $table->string('req_medical_lab', 10)->default('Pending');
            $table->string('req_landbank_account', 10)->default('Pending');
            $table->string('req_assumption_of_duty', 10)->default('Pending');
            $table->string('req_driver_license', 10)->default('Pending');
            $table->string('req_or_cr', 10)->default('Pending');
            $table->date('date_submitted')->nullable();
            $table->string('requirements_status', 10)->default('Incomplete')->index();
            $table->unsignedTinyInteger('requirements_missing')->default(10);
            $table->text('remarks')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('hired_at')->nullable();
            $table->timestamp('requirements_email_sent_at')->nullable();
            $table->string('requirements_email_to', 254)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
