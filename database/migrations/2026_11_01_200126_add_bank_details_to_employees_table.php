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
        Schema::table('employees', function (Blueprint $table) {
            $table->string('bank_name', 120)->nullable()->after('philhealth');
            $table->string('bank_branch', 120)->nullable()->after('bank_name');
            $table->string('bank_account_name', 160)->nullable()->after('bank_branch');
            $table->string('bank_account_number', 40)->nullable()->after('bank_account_name')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'bank_branch', 'bank_account_name', 'bank_account_number']);
        });
    }
};
