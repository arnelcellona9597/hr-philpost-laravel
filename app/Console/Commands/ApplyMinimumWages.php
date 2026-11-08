<?php

namespace App\Console\Commands;

use App\Services\MinimumWageHistory;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hr:apply-minimum-wages {--commit : Save the records. Without it nothing is changed} {--force : With --commit, do not ask first}')]
#[Description('Give active COS workers their compensation history at the regional minimum wage, with the 2% then 15% premium')]
class ApplyMinimumWages extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MinimumWageHistory $wages): int
    {
        $plan = $wages->plan();
        $records = array_sum(array_map(fn (array $person): int => count($person['steps']), $plan['people']));

        $this->info(sprintf('%d active COS workers would get compensation records (%d records in all).', count($plan['people']), $records));
        $this->table(['Region', 'People'], collect($plan['regions'])->map(fn (int $people, string $region): array => [$region, $people])->values()->all());
        $this->line(sprintf('Premium: %d%% before the switch date, %d%% from it (Region XIII from %s, other regions from %s).', MinimumWageHistory::EARLY_PREMIUM, MinimumWageHistory::LATE_PREMIUM, MinimumWageHistory::PREMIUM_SWITCH['Region XIII'], MinimumWageHistory::DEFAULT_SWITCH));

        if ($plan['people'] !== []) {
            $sample = $plan['people'][0];
            $this->line("Example, {$sample['employee']->full_name}:");
            $this->table(['From', 'Daily rate', 'Premium', 'Total'], array_map(fn (array $step): array => [
                $step['effective_on'],
                Money::format($step['amount_centavos']),
                "{$step['premium_percent']}%",
                Money::format((int) round($step['amount_centavos'] * (100 + $step['premium_percent']) / 100)),
            ], $sample['steps']));
        }

        foreach ($plan['skipped'] as $reason => $names) {
            if ($names !== []) {
                $this->warn(sprintf('Left out, %s: %d', $reason, count($names)));

                foreach ($names as $name) {
                    $this->line("  - {$name}");
                }
            }
        }

        if (! $this->option('commit')) {
            $this->newLine();
            $this->info('Nothing was changed. Run again with --commit to save.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Save these compensation records? Take a backup first (php8.5 artisan hr:backup).')) {
            $this->warn('Nothing was changed.');

            return self::FAILURE;
        }

        $result = $wages->apply($plan);
        $this->info("Done: {$result['records']} compensation records for {$result['people']} people.");

        return self::SUCCESS;
    }
}
