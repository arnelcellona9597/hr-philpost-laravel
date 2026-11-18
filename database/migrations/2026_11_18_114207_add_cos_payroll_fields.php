<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_EARNINGS = ['Basic Pay', 'PERA', 'Overtime', 'Allowances'];

    private const OLD_DEDUCTIONS = ['Withholding Tax', 'GSIS / SSS', 'PhilHealth', 'Pag-IBIG', 'Loans'];

    private const NEW_EARNINGS = ['Gross', 'Overtime Pay'];

    private const NEW_DEDUCTIONS = ['Late', 'SSS', 'HDMF', 'CMPEAI'];

    /**
     * The COS payroll columns of the office's payroll workbook, the standing deductions each worker
     * carries into every period, and the matching payslip items unless HR already changed them.
     */
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->string('batch', 20)->nullable()->after('pay_date');
        });

        Schema::table('payslips', function (Blueprint $table): void {
            $table->string('batch_label', 20)->nullable()->after('classification');
            $table->string('specific_station', 120)->nullable()->after('batch_label');
            $table->string('period_covered', 40)->nullable()->after('specific_station');
            $table->unsignedBigInteger('daily_rate_centavos')->default(0)->after('period_covered');
            $table->decimal('premium_percent', 5, 2)->default(15)->after('daily_rate_centavos');
            $table->decimal('days_worked', 6, 2)->default(0)->after('premium_percent');
            $table->unsignedInteger('late_minutes')->default(0)->after('days_worked');
            $table->decimal('ot_hours', 6, 2)->default(0)->after('late_minutes');
            $table->decimal('ot_rest_hours', 6, 2)->default(0)->after('ot_hours');
            $table->unsignedBigInteger('sss_centavos')->default(0)->after('ot_rest_hours');
            $table->unsignedBigInteger('hdmf_centavos')->default(0)->after('sss_centavos');
            $table->unsignedBigInteger('cmpeai_centavos')->default(0)->after('hdmf_centavos');
            $table->decimal('absences', 6, 2)->default(0)->after('cmpeai_centavos');
            $table->unsignedInteger('tardiness')->default(0)->after('absences');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->unsignedBigInteger('sss_deduction_centavos')->nullable()->after('bank_account_number');
            $table->unsignedBigInteger('hdmf_deduction_centavos')->nullable()->after('sss_deduction_centavos');
            $table->unsignedBigInteger('cmpeai_deduction_centavos')->nullable()->after('hdmf_deduction_centavos');
        });

        $this->swapItems(self::OLD_EARNINGS, self::OLD_DEDUCTIONS, self::NEW_EARNINGS, self::NEW_DEDUCTIONS);
    }

    public function down(): void
    {
        $this->swapItems(self::NEW_EARNINGS, self::NEW_DEDUCTIONS, self::OLD_EARNINGS, self::OLD_DEDUCTIONS);

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['sss_deduction_centavos', 'hdmf_deduction_centavos', 'cmpeai_deduction_centavos']);
        });

        Schema::table('payslips', function (Blueprint $table): void {
            $table->dropColumn(['batch_label', 'specific_station', 'period_covered', 'daily_rate_centavos', 'premium_percent', 'days_worked', 'late_minutes', 'ot_hours', 'ot_rest_hours', 'sss_centavos', 'hdmf_centavos', 'cmpeai_centavos', 'absences', 'tardiness']);
        });

        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->dropColumn('batch');
        });
    }

    /**
     * Replace the payslip items only where they are still exactly the ones being replaced.
     *
     * @param  list<string>  $fromEarnings
     * @param  list<string>  $fromDeductions
     * @param  list<string>  $toEarnings
     * @param  list<string>  $toDeductions
     */
    private function swapItems(array $fromEarnings, array $fromDeductions, array $toEarnings, array $toDeductions): void
    {
        foreach (DB::table('payslip_settings')->get(['id', 'earning_items', 'deduction_items']) as $row) {
            if (json_decode((string) $row->earning_items, true) === $fromEarnings && json_decode((string) $row->deduction_items, true) === $fromDeductions) {
                DB::table('payslip_settings')->where('id', $row->id)->update([
                    'earning_items' => json_encode($toEarnings),
                    'deduction_items' => json_encode($toDeductions),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
