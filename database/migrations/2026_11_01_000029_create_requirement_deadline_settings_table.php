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
        Schema::create('requirement_deadline_settings', function (Blueprint $table) {
            $table->id();
            $table->json('itr');
            $table->json('sworn');
            $table->json('pds');
            $table->json('ipcr_jan_jun');
            $table->json('ipcr_jul_dec');
            $table->json('overrides');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requirement_deadline_settings');
    }
};
