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
        Schema::create('remittance_settings', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10)->unique();
            $table->string('title', 160);
            $table->text('letterhead_lines')->nullable();
            $table->string('employer_id', 40)->nullable();
            $table->string('employer_name', 160)->nullable();
            $table->string('employer_address', 255)->nullable();
            $table->string('employer_contact', 120)->nullable();
            $table->string('form_code', 40)->nullable();
            $table->string('membership_program', 60)->nullable();
            $table->unsignedBigInteger('default_amount_centavos')->default(0);
            $table->unsignedBigInteger('default_employer_share_centavos')->default(0);
            $table->unsignedBigInteger('default_compensation_centavos')->default(0);
            $table->unsignedSmallInteger('service_charge_basis_points')->default(0);
            $table->string('prepared_by_name', 120)->nullable();
            $table->string('prepared_by_title', 120)->nullable();
            $table->string('noted_by_name', 120)->nullable();
            $table->string('noted_by_title', 120)->nullable();
            $table->text('certification_text')->nullable();
            $table->string('voucher_payee', 160)->nullable();
            $table->string('voucher_address', 255)->nullable();
            $table->text('voucher_particulars')->nullable();
            $table->timestamps();
        });

        Schema::create('voucher_settings', function (Blueprint $table) {
            $table->id();
            $table->string('mode_of_payment', 40)->default('Check');
            $table->string('fund_cluster', 60)->nullable();
            $table->string('responsibility_center', 60)->nullable();
            $table->string('supervisor_name', 120)->nullable();
            $table->string('supervisor_title', 120)->nullable();
            $table->string('accountant_name', 120)->nullable();
            $table->string('accountant_title', 120)->nullable();
            $table->string('approver_name', 120)->nullable();
            $table->string('approver_title', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('remittances', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 12)->default('Draft')->index();
            $table->string('voucher_number', 40)->nullable();
            $table->date('voucher_date')->nullable();
            $table->string('mode_of_payment', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['kind', 'year', 'month']);
        });

        Schema::create('remittance_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remittance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('last_name', 80);
            $table->string('first_name', 80);
            $table->string('middle_name', 80)->nullable();
            $table->string('extension', 10)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('station', 120)->nullable();
            $table->string('member_number', 30)->nullable()->index();
            $table->unsignedBigInteger('amount_centavos')->default(0);
            $table->unsignedBigInteger('employer_share_centavos')->default(0);
            $table->unsignedBigInteger('compensation_centavos')->default(0);
            $table->string('remarks', 255)->nullable();
            $table->timestamps();

            $table->index(['remittance_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remittance_lines');
        Schema::dropIfExists('remittances');
        Schema::dropIfExists('voucher_settings');
        Schema::dropIfExists('remittance_settings');
    }
};
