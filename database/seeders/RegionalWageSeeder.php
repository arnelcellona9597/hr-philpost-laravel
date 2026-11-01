<?php

namespace Database\Seeders;

use App\Enums\Region;
use App\Models\WageOrder;
use App\Support\AuditTrail;
use Illuminate\Database\Seeder;

/**
 * The daily rates in the office's COS record, by the date each took effect. The record gives no wage
 * order numbers, so those stay blank for HR to fill in. Safe to run again: existing rows are left alone.
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
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        AuditTrail::withoutRecording(function (): void {
            foreach (self::RATES as $region => $rates) {
                foreach ($rates as $date => $rate) {
                    WageOrder::query()->firstOrCreate(
                        ['region' => $region, 'sector' => self::SECTOR, 'effective_on' => $date],
                        ['daily_rate_centavos' => $rate, 'remarks' => 'From the COS record. Add the wage order number.'],
                    );
                }
            }
        });
    }
}
