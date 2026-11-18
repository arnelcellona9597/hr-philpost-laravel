<?php

namespace Database\Seeders;

use App\Enums\Region;
use App\Models\WageOrder;
use App\Support\AuditTrail;
use Illuminate\Database\Seeder;

/**
 * The daily rates in the office's COS record, by the date each took effect, plus the published Region XII
 * and BARMM orders the record lacked. The COS record gives no wage order numbers, so those stay blank for HR. Safe to run again: existing rows are left alone.
 */
class RegionalWageSeeder extends Seeder
{
    public const SECTOR = 'General';

    public const RATES = [
        Region::RegionXi->value => [
            '2017-04-01' => 31_700,
            '2017-05-01' => 34_000,
            '2018-08-16' => 37_000,
            '2019-02-16' => 39_600,
            '2022-06-19' => 42_700,
            '2023-01-01' => 44_300,
            '2024-03-06' => 46_200,
            '2024-09-01' => 48_100,
            '2025-03-07' => 51_000,
            '2026-03-13' => 52_500,
            '2026-09-01' => 54_000,
        ],
        Region::RegionXiii->value => [
            '2025-07-01' => 43_500,
            '2026-01-01' => 45_500,
            '2026-05-01' => 47_500,
        ],
        Region::RegionXii->value => [
            '2025-12-15' => 46_000,
            '2026-11-19' => 47_600,
            '2027-01-01' => 50_100,
        ],
        Region::Barmm->value => [
            '2026-08-06' => 43_600,
            '2026-12-01' => 46_100,
        ],
    ];

    /**
     * Wage orders that weren't in the COS record, taken from the published orders (non-agriculture;
     * BARMM at the Cotabato City rate). HR should check them against the official matrices.
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    public const PUBLISHED = [
        Region::RegionXii->value => [
            '2025-12-15' => ['RB XII-25', 'Non-agriculture. Check against the official RTWPB XII matrix.'],
            '2026-11-19' => ['XII-26 (1st tranche)', 'Non-agriculture. Some reports give Nov 22; check against the official order.'],
            '2027-01-01' => ['XII-26 (2nd tranche)', 'Non-agriculture. Check against the official RTWPB XII matrix.'],
        ],
        Region::Barmm->value => [
            '2026-08-06' => ['BARMM-05 (1st tranche)', 'Non-agriculture, Cotabato City rate. Other BARMM areas may differ; effective date to confirm.'],
            '2026-12-01' => ['BARMM-05 (2nd tranche)', 'Non-agriculture, Cotabato City rate. Other BARMM areas may differ.'],
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        AuditTrail::withoutRecording(function (): void {
            foreach (self::RATES as $region => $rates) {
                foreach ($rates as $date => $rate) {
                    [$number, $remarks] = self::PUBLISHED[$region][$date] ?? [null, 'From the COS record. Add the wage order number.'];

                    WageOrder::query()->firstOrCreate(
                        ['region' => $region, 'sector' => self::SECTOR, 'effective_on' => $date],
                        ['order_number' => $number, 'daily_rate_centavos' => $rate, 'remarks' => $remarks],
                    );
                }
            }
        });
    }
}
