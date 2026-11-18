<?php

namespace Database\Seeders;

use App\Enums\Region;
use App\Models\WageOrder;
use App\Support\AuditTrail;
use Illuminate\Database\Seeder;

/**
 * The daily rates in the office's COS record, by the date each took effect, plus the published Region XII
 * and BARMM (by area) orders the record lacked. The COS record gives no wage order numbers, so those stay blank for HR. Safe to run again: existing rows are left alone.
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
     * Wage orders that weren't in the COS record, taken from the published orders (non-agriculture). BARMM-05
     * sets rates by area: Cotabato City, and the Maguindanao provinces (Datu Odin Sinsuat and Parang are in
     * Maguindanao del Norte, Datu Piang in Maguindanao del Sur). HR should check them against the official matrices.
     *
     * @var list<array{region: string, area: string, effective_on: string, rate: int, order_number: string, remarks: string}>
     */
    public const PUBLISHED = [
        ['region' => 'Region XII', 'area' => '', 'effective_on' => '2025-12-15', 'rate' => 46_000, 'order_number' => 'RB XII-25', 'remarks' => 'Non-agriculture. Check against the official RTWPB XII matrix.'],
        ['region' => 'Region XII', 'area' => '', 'effective_on' => '2026-11-19', 'rate' => 47_600, 'order_number' => 'XII-26 (1st tranche)', 'remarks' => 'Non-agriculture. Some reports give Nov 22; check against the official order.'],
        ['region' => 'Region XII', 'area' => '', 'effective_on' => '2027-01-01', 'rate' => 50_100, 'order_number' => 'XII-26 (2nd tranche)', 'remarks' => 'Non-agriculture. Check against the official RTWPB XII matrix.'],
        ['region' => 'BARMM', 'area' => self::COTABATO_CITY, 'effective_on' => '2026-08-06', 'rate' => 43_600, 'order_number' => 'BARMM-05 (1st tranche)', 'remarks' => 'Non-agriculture. Check against the official BARMM-05 matrix; effective date to confirm.'],
        ['region' => 'BARMM', 'area' => self::COTABATO_CITY, 'effective_on' => '2026-12-01', 'rate' => 46_100, 'order_number' => 'BARMM-05 (2nd tranche)', 'remarks' => 'Non-agriculture. Check against the official BARMM-05 matrix.'],
        ['region' => 'BARMM', 'area' => self::MAGUINDANAO_NORTE, 'effective_on' => '2026-08-06', 'rate' => 41_100, 'order_number' => 'BARMM-05 (1st tranche)', 'remarks' => 'Non-agriculture, incl. Datu Odin Sinsuat and Parang. Check against the official BARMM-05 matrix; effective date to confirm.'],
        ['region' => 'BARMM', 'area' => self::MAGUINDANAO_NORTE, 'effective_on' => '2026-12-01', 'rate' => 43_600, 'order_number' => 'BARMM-05 (2nd tranche)', 'remarks' => 'Non-agriculture, incl. Datu Odin Sinsuat and Parang. Check against the official BARMM-05 matrix.'],
        ['region' => 'BARMM', 'area' => self::MAGUINDANAO_SUR, 'effective_on' => '2026-08-06', 'rate' => 41_100, 'order_number' => 'BARMM-05 (1st tranche)', 'remarks' => 'Non-agriculture, incl. Datu Piang. Check against the official BARMM-05 matrix; effective date to confirm.'],
        ['region' => 'BARMM', 'area' => self::MAGUINDANAO_SUR, 'effective_on' => '2026-12-01', 'rate' => 43_600, 'order_number' => 'BARMM-05 (2nd tranche)', 'remarks' => 'Non-agriculture, incl. Datu Piang. Check against the official BARMM-05 matrix.'],
    ];

    public const COTABATO_CITY = 'Cotabato City';

    public const MAGUINDANAO_NORTE = 'Maguindanao del Norte';

    public const MAGUINDANAO_SUR = 'Maguindanao del Sur';

    /**
     * Regions this seeder gives wage orders to.
     *
     * @return list<string>
     */
    public static function regions(): array
    {
        return array_values(array_unique([...array_keys(self::RATES), ...array_column(self::PUBLISHED, 'region')]));
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        AuditTrail::withoutRecording(function (): void {
            foreach (self::RATES as $region => $rates) {
                foreach ($rates as $date => $rate) {
                    WageOrder::query()->firstOrCreate(
                        ['region' => $region, 'area' => '', 'sector' => self::SECTOR, 'effective_on' => $date],
                        ['daily_rate_centavos' => $rate, 'remarks' => 'From the COS record. Add the wage order number.'],
                    );
                }
            }

            foreach (self::PUBLISHED as $order) {
                WageOrder::query()->firstOrCreate(
                    ['region' => $order['region'], 'area' => $order['area'], 'sector' => self::SECTOR, 'effective_on' => $order['effective_on']],
                    ['order_number' => $order['order_number'], 'daily_rate_centavos' => $order['rate'], 'remarks' => $order['remarks']],
                );
            }
        });
    }
}
