<?php

namespace App\Support;

use App\Models\ContractSetting;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipSetting;
use App\Models\SiteSetting;

/**
 * The printable COS payroll sheet: workers grouped by official station with subtotals, a grand total
 * and the signatories. The browser print page and the PDF both draw from this.
 *
 * @phpstan-type Totals array{days: float, gross: int, late: int, overtime: int, after: int, sss: int, hdmf: int, cmpeai: int, net: int}
 * @phpstan-type Row array{number: int, slip: Payslip, pay: array<string, int>}
 */
class PayrollRegister
{
    /**
     * @return array{groups: list<array{station: string, rows: list<Row>, totals: Totals}>, totals: Totals, count: int, signatories: list<array{0: string, 1: string, 2: string}>}
     */
    public static function build(PayrollPeriod $period): array
    {
        $slips = $period->payslips()->orderBy('station')->orderBy('full_name')->get();
        $groups = [];
        $number = 0;

        foreach ($slips->groupBy(fn (Payslip $slip): string => $slip->station ?: '—') as $station => $stationSlips) {
            $rows = [];

            foreach ($stationSlips as $slip) {
                $rows[] = ['number' => ++$number, 'slip' => $slip, 'pay' => $slip->cosPay()];
            }

            $groups[] = ['station' => (string) $station, 'rows' => $rows, 'totals' => self::totals($rows)];
        }

        return [
            'groups' => $groups,
            'totals' => self::totals(array_merge(...array_column($groups, 'rows') ?: [[]])),
            'count' => $slips->count(),
            'signatories' => self::signatories(),
        ];
    }

    /**
     * The office letterhead (Branding & theme), drawn as on certificates.
     *
     * @param  array{0: float, 1: float}|null  $logoSize
     */
    public static function letterhead(?string $logoUrl, ?array $logoSize, bool $pdf): string
    {
        $site = SiteSetting::current();

        return CertificateRenderer::html([['type' => 'letterhead', ...$site->letterhead()]], [
            'companyName' => (string) $site->company_name,
            'font' => 'arial',
            'letterheadLogoUrl' => $logoUrl,
            'letterheadLogoSize' => $logoSize,
            'pdf' => $pdf,
        ]);
    }

    /**
     * @param  list<Row>  $rows
     * @return Totals
     */
    private static function totals(array $rows): array
    {
        $sum = fn (callable $value): int => array_sum(array_map($value, $rows));

        return [
            'days' => (float) array_sum(array_map(fn (array $row): float => (float) $row['slip']->days_worked, $rows)),
            'gross' => $sum(fn (array $row): int => $row['pay']['gross']),
            'late' => $sum(fn (array $row): int => $row['pay']['late']),
            'overtime' => $sum(fn (array $row): int => $row['pay']['overtime']),
            'after' => $sum(fn (array $row): int => $row['pay']['gross_after_late']),
            'sss' => $sum(fn (array $row): int => (int) $row['slip']->sss_centavos),
            'hdmf' => $sum(fn (array $row): int => (int) $row['slip']->hdmf_centavos),
            'cmpeai' => $sum(fn (array $row): int => (int) $row['slip']->cmpeai_centavos),
            'net' => $sum(fn (array $row): int => $row['pay']['net']),
        ];
    }

    /**
     * Prepared by and certified correct from the payslip settings; the HRM Chief from Officials and
     * wages certifies when the payslip settings name no one.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function signatories(): array
    {
        $settings = PayslipSetting::current();
        $officials = ContractSetting::current();
        $signatories = [];

        if ($settings->prepared_by_name) {
            $signatories[] = ['Prepared by:', $settings->prepared_by_name, (string) $settings->prepared_by_title];
        }

        if ($settings->certified_by_name) {
            $signatories[] = ['Certified correct:', $settings->certified_by_name, (string) $settings->certified_by_title];
        } elseif ($officials->hrm_chief_name) {
            $signatories[] = ['Certified correct:', $officials->hrm_chief_name, (string) ($officials->hrm_chief_title ?: ContractSetting::OFFICIALS['hrm_chief'])];
        }

        return $signatories;
    }
}
