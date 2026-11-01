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
        Schema::create('wage_orders', function (Blueprint $table) {
            $table->id();
            $table->string('region', 20);
            $table->string('order_number', 60);
            $table->string('sector', 60);
            $table->unsignedBigInteger('daily_rate_centavos');
            $table->date('effective_on')->index();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['region', 'sector', 'effective_on']);
        });

        Schema::create('pay_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_on');
            $table->string('change_type', 20);
            $table->string('rate_type', 10);
            $table->unsignedBigInteger('amount_centavos');
            $table->foreignId('wage_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 120)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'effective_on']);
        });

        Schema::create('position_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_on');
            $table->string('change_type', 20);
            $table->string('from_position', 150)->nullable();
            $table->string('to_position', 150);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'effective_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('position_changes');
        Schema::dropIfExists('pay_changes');
        Schema::dropIfExists('wage_orders');
    }
};
