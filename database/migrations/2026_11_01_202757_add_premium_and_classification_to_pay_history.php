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
        Schema::table('wage_orders', function (Blueprint $table) {
            $table->string('order_number', 60)->nullable()->change();
        });

        Schema::table('pay_changes', function (Blueprint $table) {
            $table->decimal('premium_percent', 5, 2)->default(0)->after('amount_centavos');
        });

        Schema::table('position_changes', function (Blueprint $table) {
            $table->string('from_classification', 20)->nullable()->after('to_position');
            $table->string('to_classification', 20)->nullable()->after('from_classification');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('position_changes', function (Blueprint $table) {
            $table->dropColumn(['from_classification', 'to_classification']);
        });

        Schema::table('pay_changes', function (Blueprint $table) {
            $table->dropColumn('premium_percent');
        });

        Schema::table('wage_orders', function (Blueprint $table) {
            $table->string('order_number', 60)->nullable(false)->change();
        });
    }
};
