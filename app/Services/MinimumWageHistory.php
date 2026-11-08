<?php

namespace App\Services;

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\PayChangeType;
use App\Enums\RateType;
use App\Enums\Region;
use App\Models\Employee;
use App\Models\WageOrder;
use App\Support\HrDate;
use Database\Seeders\RegionalWageSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Gives active COS workers their compensation history at the regional minimum wage: the rate in force when they
 * were hired, then every later rate of their region, each with the premium of its time. The premium is 2% before
 * the region's switch date and 15% from it, as in the office's records. People who already have compensation
 * records are left alone.
 *
 * @phpstan-type Step array{effective_on: string, amount_centavos: int, premium_percent: int, change_type: PayChangeType}
 */
class MinimumWageHistory
{
    /**
     * When the premium went from 2% to 15%, by region. Regions not listed switch on the default date.
     */
    public const PREMIUM_SWITCH = [Region::RegionXiii->value => '2026-01-01'];

    public const DEFAULT_SWITCH = '2026-03-13';

    public const EARLY_PREMIUM = 2;

    public const LATE_PREMIUM = 15;

    public function __construct(private PayHistoryService $pay) {}

    /**
     * Who would get which rates, without changing anything.
     *
     * @return array{people: list<array{employee: Employee, steps: list<Step>}>, skipped: array<string, list<string>>, regions: array<string, int>}
     */
    public function plan(): array
    {
        $people = [];
        $skipped = ['no region' => [], 'no rates for the region' => [], 'no date hired' => [], 'already has compensation records' => []];
        $regions = [];

        $employees = Employee::query()
            ->with('station')
            ->withCount('payChanges')
            ->where('employment_status', EmploymentStatus::Active)
            ->where('classification', Classification::Cos)
            ->orderBy('full_name')
            ->get();

        foreach ($employees as $employee) {
            $region = $this->pay->regionOf($employee);
            $label = "{$employee->full_name} ({$employee->employee_id})";

            if ($employee->pay_changes_count > 0) {
                $skipped['already has compensation records'][] = $label;
            } elseif ($employee->date_hired === null) {
                $skipped['no date hired'][] = $label;
            } elseif ($region === null) {
                $skipped['no region'][] = $label;
            } elseif (($rates = $this->rates($region)) === []) {
                $skipped['no rates for the region'][] = "{$label}, {$region->value}";
            } else {
                $people[] = ['employee' => $employee, 'steps' => $this->steps($employee->date_hired->toDateString(), $rates, $region)];
                $regions[$region->value] = ($regions[$region->value] ?? 0) + 1;
            }
        }

        return ['people' => $people, 'skipped' => $skipped, 'regions' => $regions];
    }

    /**
     * Save a plan in one transaction. The region rates are added as wage orders first if they are missing.
     *
     * @param  array{people: list<array{employee: Employee, steps: list<Step>}>}  $plan
     * @return array{people: int, records: int}
     */
    public function apply(array $plan): array
    {
        app(RegionalWageSeeder::class)->run();
        $records = 0;

        DB::transaction(function () use ($plan, &$records): void {
            foreach ($plan['people'] as ['employee' => $employee, 'steps' => $steps]) {
                $region = $this->pay->regionOf($employee);

                foreach ($steps as $step) {
                    $order = WageOrder::query()->where('region', $region?->value)->whereDate('effective_on', $step['effective_on'])->orderByDesc('daily_rate_centavos')->first();

                    $this->pay->record($employee, [
                        'effective_on' => $step['effective_on'],
                        'change_type' => $step['change_type']->value,
                        'rate_type' => RateType::Daily->value,
                        'amount_centavos' => $step['amount_centavos'],
                        'premium_percent' => $step['premium_percent'],
                        'wage_order_id' => $order?->id,
                        'reference' => 'Regional minimum wage',
                    ]);
                    $records++;
                }
            }
        });

        return ['people' => count($plan['people']), 'records' => $records];
    }

    /**
     * The region's minimum daily rates by date: saved wage orders, else the rates from the office's COS record.
     *
     * @return array<string, int> effective date => centavos, oldest first
     */
    public function rates(Region $region): array
    {
        $saved = WageOrder::query()->where('region', $region->value)->orderBy('effective_on')->get()
            ->groupBy(fn (WageOrder $order): string => $order->effective_on->toDateString())
            ->map(fn ($orders): int => (int) $orders->max('daily_rate_centavos'))
            ->all();

        $rates = $saved !== [] ? $saved : (RegionalWageSeeder::RATES[$region->value] ?? []);
        ksort($rates);

        return $rates;
    }

    public function premiumOn(Region $region, string $date): int
    {
        return $date >= (self::PREMIUM_SWITCH[$region->value] ?? self::DEFAULT_SWITCH) ? self::LATE_PREMIUM : self::EARLY_PREMIUM;
    }

    /**
     * The rate in force on the hire date (or the first known rate, if hired earlier), then each later rate.
     *
     * @param  array<string, int>  $rates
     * @return list<Step>
     */
    private function steps(string $hired, array $rates, Region $region): array
    {
        $dates = array_keys($rates);
        $before = array_values(array_filter($dates, fn (string $date): bool => $date <= $hired));
        $start = $before === [] ? $dates[0] : max($hired, end($before));
        $startRate = $before === [] ? $rates[$dates[0]] : $rates[end($before)];

        $steps = [['effective_on' => $start, 'amount_centavos' => $startRate, 'premium_percent' => $this->premiumOn($region, $start), 'change_type' => PayChangeType::Starting]];

        foreach ($rates as $date => $rate) {
            if ($date > $start && $date <= HrDate::today()) {
                $steps[] = ['effective_on' => $date, 'amount_centavos' => $rate, 'premium_percent' => $this->premiumOn($region, $date), 'change_type' => PayChangeType::WageOrder];
            }
        }

        return $steps;
    }
}
