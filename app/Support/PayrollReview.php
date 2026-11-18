<?php

namespace App\Support;

use App\Enums\Region;
use App\Models\ContractSetting;
use App\Models\Payslip;
use App\Models\WageOrder;
use App\Services\PayHistoryService;
use Illuminate\Support\Collection;

/**
 * What a reviewer checks before a payroll period is approved: per region (and wage area inside it), the wage order in force,
 * the minimum, the premium and the adjusted daily rate, how many workers fall back to the regional
 * minimum and how many are paid below it, plus workers with no rate at all.
 *
 * @phpstan-type RegionRow array{region: string, order: WageOrder|null, minimum: int|null, premiums: list<string>, adjusted: int|null, workers: int, regional: int, below: int}
 */
class PayrollReview
{
    /**
     * @param  Collection<int, Payslip>  $slips
     * @return array{regions: list<RegionRow>, no_rate: int, below: int, regional: int}
     */
    public static function build(Collection $slips, string $periodEnd): array
    {
        $regions = [];
        $pay = app(PayHistoryService::class);

        // Payslips made before regions were recorded fall back to the worker's current station region and area.
        $regionOf = fn (Payslip $slip): string => $slip->region ?: ($slip->employee ? $pay->regionOf($slip->employee)?->value : null) ?: 'No region';
        $areaOf = fn (Payslip $slip): string => (string) ($slip->wage_area ?: ($slip->employee && ! $slip->region ? $pay->wageAreaOf($slip->employee) : null));

        foreach ($slips->groupBy(fn (Payslip $slip): string => $regionOf($slip).'|'.$areaOf($slip)) as $key => $group) {
            [$region, $area] = explode('|', (string) $key, 2);
            $regional = $group->firstWhere('rate_source', 'regional');
            $order = $pay->minimumFor(Region::tryFrom($region), $periodEnd, $area);
            $minimum = $order?->daily_rate_centavos;
            $premiums = $group->map(fn (Payslip $slip): string => rtrim(rtrim((string) $slip->premium_percent, '0'), '.').'%')->unique()->sort()->values()->all();
            $defaultPremium = (float) ($regional?->premium_percent ?? ContractSetting::current()->premium_percent);

            $regions[] = [
                'region' => $area !== '' ? "{$region} · {$area}" : $region,
                'order' => $order,
                'minimum' => $minimum,
                'premiums' => $premiums,
                'adjusted' => $minimum === null ? null : $minimum + (int) round($minimum * $defaultPremium / 100),
                'workers' => $group->count(),
                'regional' => $group->where('rate_source', 'regional')->count(),
                'below' => $group->filter(fn (Payslip $slip): bool => $slip->isBelowMinimum())->count(),
            ];
        }

        usort($regions, fn (array $a, array $b): int => strcmp($a['region'], $b['region']));

        return [
            'regions' => $regions,
            'no_rate' => $slips->where('daily_rate_centavos', 0)->count(),
            'below' => array_sum(array_column($regions, 'below')),
            'regional' => array_sum(array_column($regions, 'regional')),
        ];
    }
}
