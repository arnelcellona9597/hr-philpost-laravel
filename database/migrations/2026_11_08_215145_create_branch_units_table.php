<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Divisions, sections and units sit under a branch. A unit may wait without a branch until HR places it.
     * The free text kept in Station / Division / Unit becomes units, and employees point at them.
     */
    public function up(): void
    {
        Schema::create('branch_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('name', 255);
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('station_id')->constrained('branch_units')->restrictOnDelete();
        });

        $made = [];

        foreach (DB::table('employees')->whereNotNull('station_unit')->where('station_unit', '<>', '')->get(['id', 'station_id', 'station_unit']) as $employee) {
            $name = trim(preg_replace('/\s+/u', ' ', $employee->station_unit));
            $key = ($employee->station_id ?? 0).'|'.mb_strtolower($name);

            $made[$key] ??= DB::table('branch_units')->insertGetId(['branch_id' => $employee->station_id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);

            DB::table('employees')->where('id', $employee->id)->update(['unit_id' => $made[$key]]);
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('station_unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('station_unit', 255)->nullable()->after('station_id');
        });

        foreach (DB::table('employees')->whereNotNull('unit_id')->get(['id', 'unit_id']) as $employee) {
            DB::table('employees')->where('id', $employee->id)->update(['station_unit' => DB::table('branch_units')->where('id', $employee->unit_id)->value('name')]);
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
        });

        Schema::dropIfExists('branch_units');
    }
};
