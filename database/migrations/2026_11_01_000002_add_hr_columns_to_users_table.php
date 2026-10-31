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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('avatar_id')->nullable()->after('name')->constrained('media')->restrictOnDelete();
            $table->string('role', 20)->default('hr-staff')->after('avatar_id');
            $table->string('status', 10)->default('pending')->after('role')->index();
            $table->timestamp('approved_at')->nullable()->after('email_verified_at');
            $table->unsignedTinyInteger('login_attempts')->default(0)->after('password');
            $table->timestamp('locked_until')->nullable()->after('login_attempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('avatar_id');
            $table->dropColumn(['role', 'status', 'approved_at', 'login_attempts', 'locked_until']);
        });
    }
};
