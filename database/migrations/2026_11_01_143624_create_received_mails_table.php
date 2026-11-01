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
        Schema::create('received_mails', function (Blueprint $table) {
            $table->id();
            $table->date('received_on')->index();
            // Not unique: the same number can come back, and in-house mail is numbered by hand.
            $table->string('tracking_number', 60)->index();
            $table->string('post_office', 120)->index();
            $table->text('description');
            $table->text('remarks')->nullable();
            $table->string('received_by', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('received_mails');
    }
};
