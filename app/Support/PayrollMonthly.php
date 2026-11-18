<?php

namespace App\Support;

use App\Models\PayrollPeriod;
use App\Models\Payslip;
use Illuminate\Support\Carbon;

/**
 * The consolidated COS payroll for one month: each worker's 1-15 and 16-end payslips side by side and
 * the month's total. Each half keeps its own computed amounts; nothing is divided from a monthly figure.
 *
 * @phpstan-type Half array{days: float, gross: int, late: int, overtime: int, deductions: int, net: int}
 * @phpstan-type Row array{name: string, code: string, station: string, halves: array{1: Half|null, 2: Half|null}, total: Half}
 */
class PayrollMonthly
{
    /**
     * @return array{year: int, month: int, title: string, periods: array{1: PayrollPeriod|null, 2: PayrollPeriod|null}, rows: list<Row>, totals: array{1: Half, 2: Half, month: Half}}
     */
    public static function build(int $year, int $month): array
    {
        $month = max(1, min(12, $month));
        $first = Carbon::create($year, $month, 1);
        $periods = [1 => null, 2 => null];

        foreach ([1, 2] as $half) {
            $dates = PayrollPeriod::forHalf($year, $month, $half);
            $periods[$half] = PayrollPeriod::query()
                ->where(fn ($query) => $query->where(['year' => $year, 'month' => $month, 'half' => $half])
                    ->orWhere(fn ($query) => $query->whereNull('year')->whereDate('period_start', $dates['period_start'])->whereDate('period_end', $dates['period_end'])))
                ->orderBy('id')
                ->first();
        }

        $rows = [];

        foreach ($periods as $half => $period) {
            foreach ($period?->payslips()->get() ?? [] as $slip) {
                $key = (string) $slip->employee_id;
                $rows[$key] ??= ['name' => $slip->full_name, 'code' => (string) $slip->employee_code, 'station' => (string) $slip->station, 'halves' => [1 => null, 2 => null]];
                $rows[$key]['halves'][$half] = self::half($slip);
            }
        }

        foreach ($rows as $key => $row) {
            $rows[$key]['total'] = self::sum(array_filter($row['halves']));
        }

        uasort($rows, fn (array $a, array $b): int => [$a['station'], $a['name']] <=> [$b['station'], $b['name']]);
        $rows = array_values($rows);

        return [
            'year' => $year,
            'month' => $month,
            'title' => strtoupper($first->format('F')).' 1-'.$first->copy()->endOfMonth()->format('j, Y'),
            'periods' => $periods,
            'rows' => $rows,
            'totals' => [
                1 => self::sum(array_values(array_filter(array_column(array_column($rows, 'halves'), 1)))),
                2 => self::sum(array_values(array_filter(array_column(array_column($rows, 'halves'), 2)))),
                'month' => self::sum(array_column($rows, 'total')),
            ],
        ];
    }

    /**
     * @return Half
     */
    private static function half(Payslip $slip): array
    {
        $pay = $slip->cosPay();

        return [
            'days' => (float) $slip->days_worked,
            'gross' => $pay['gross'],
            'late' => $pay['late'],
            'overtime' => $pay['overtime'],
            'deductions' => $pay['deductions'],
            'net' => (int) $slip->net_pay_centavos,
        ];
    }

    /**
     * @param  list<Half>  $halves
     * @return Half
     */
    private static function sum(array $halves): array
    {
        $total = ['days' => 0.0, 'gross' => 0, 'late' => 0, 'overtime' => 0, 'deductions' => 0, 'net' => 0];

        foreach ($halves as $half) {
            foreach ($total as $key => $value) {
                $total[$key] = $value + $half[$key];
            }
        }

        return $total;
    }
}
