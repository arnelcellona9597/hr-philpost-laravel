<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employment_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('hired_on')->index();
            $table->date('separated_on')->nullable()->index();
            $table->string('separation_type', 20)->nullable();
            $table->string('position', 150)->nullable();
            $table->foreignId('station_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('classification', 20)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'hired_on']);
        });

        // Every employee already hired starts with one period built from the dates on their record.
        DB::table('employees')->whereNotNull('date_hired')->orderBy('id')->chunkById(500, function ($employees): void {
            DB::table('employment_periods')->insert($employees->map(function (object $employee): array {
                $separated = $employee->employment_status !== 'Active';

                return [
                    'employee_id' => $employee->id,
                    'hired_on' => $employee->date_hired,
                    'separated_on' => $separated ? $employee->last_day_of_service : null,
                    'separation_type' => $separated ? $employee->employment_status : null,
                    'position' => $employee->position,
                    'station_id' => $employee->station_id,
                    'classification' => $employee->classification,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->all());
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employment_periods');
    }
};
