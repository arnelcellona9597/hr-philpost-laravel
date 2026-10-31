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
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('description', 400)->nullable();
            $table->string('prefix', 8)->default('COE');
            $table->string('paper_size', 10)->default('A4');
            $table->string('font', 10)->default('serif');
            $table->string('margin', 10)->default('normal');
            $table->json('blocks');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(100);
            $table->string('key', 60)->nullable()->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificate_templates');
    }
};
