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
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('leave_filed_to_employee')->default(true);
            $table->boolean('leave_status_to_employee')->default(true);
            $table->boolean('hired_requirements_to_applicant')->default(true);
            $table->boolean('payroll_released_to_employee')->default(true);
            $table->boolean('registration_to_admins')->default(true);
            $table->unsignedSmallInteger('hourly_limit')->default(100);
            $table->string('reply_to')->nullable();
            $table->text('footer')->nullable();
            $table->string('queue_key', 36);
            $table->text('smtp_last_error')->nullable();
            $table->timestamp('smtp_last_error_at')->nullable();
            $table->timestamp('smtp_last_ok_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
