<?php

namespace App\Console\Commands;

use App\Services\CosMasterImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

#[Signature('hr:import-cos-master {file : The COS Master File workbook (.xlsx)} {--commit : Save the changes. Without it nothing is changed} {--only-active : Load only the people whose STATUS in the file is Active} {--force : With --commit, do not ask first}')]
#[Description('Load the COS Master File sheet over the employees: update matches, add new people, never delete')]
class ImportCosMaster extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("No file at {$path}.");

            return self::FAILURE;
        }

        try {
            $import = new CosMasterImport;
            $rows = $import->read($path);
            $total = count($rows);

            if ($this->option('only-active')) {
                $rows = array_values(array_filter($rows, fn (array $row): bool => mb_strtolower((string) $row['file_status']) === 'active'));
                $this->line(sprintf('Only people marked Active are loaded: %d of %d. The other %d are left out.', count($rows), $total, $total - count($rows)));
            }

            $plan = $import->plan($rows);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $report = $this->writeReport($plan);
        $this->summary($plan, count($rows), $report);

        if (! $this->option('commit')) {
            $this->newLine();
            $this->info('Nothing was changed. Check the report, then run again with --commit to save.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Save these changes to the live data? Take a backup first (php8.5 artisan hr:backup).')) {
            $this->warn('Nothing was changed.');

            return self::FAILURE;
        }

        $result = $import->apply($plan);

        $this->info(sprintf('Done: %d employees updated, %d added, %d employment periods written. No branches were created or changed.', $result['updated'], $result['created'], $result['periods']));

        return self::SUCCESS;
    }

    /**
     * @param  array{items: list<array<string, mixed>>, branchesForNew: array{found: int, none: int}, counts: array<string, int>, missing: list<string>}  $plan
     */
    private function summary(array $plan, int $rows, string $report): void
    {
        $this->info("{$rows} people read from the file.");
        $this->table(['Result', 'People'], [
            ['Matched: will be updated', $plan['counts']['update']],
            ['Not in the system: will be added', $plan['counts']['new']],
            ['Skipped (see the report)', $plan['counts']['skip']],
            ['In the system but not in the file (left alone)', count($plan['missing'])],
        ]);

        $how = collect($plan['items'])->where('action', 'update')->countBy('how')->map(fn (int $count, string $name): array => [$name, $count])->values()->all();
        $this->table(['Matched by', 'People'], $how);

        $this->line(sprintf('Station / Division / Unit is set from the file for everyone. Branches are not touched; of the new people, %d match an existing branch and %d are left without one.', $plan['branchesForNew']['found'], $plan['branchesForNew']['none']));

        $warnings = collect($plan['items'])->sum(fn (array $item): int => count($item['row']['warnings']));
        $this->line("{$warnings} warnings (values left unchanged); every one is in the report.");
        $this->line("Report (contains personal data, delete it afterwards): {$report}");
    }

    /**
     * @param  array{items: list<array<string, mixed>>}  $plan
     */
    private function writeReport(array $plan): string
    {
        $directory = storage_path('app/private/imports');
        File::ensureDirectoryExists($directory, 0700);
        $path = $directory.'/cos-master-report-'.now()->format('Ymd-His').'.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, ['Line', 'Name', 'Result', 'Matched by', 'Employee ID given', 'Station / Division / Unit in the file', 'Branch for a new person', 'Why skipped', 'Warnings']);

        foreach ($plan['items'] as $item) {
            $row = $item['row'];
            fputcsv($handle, [
                $row['line'], $row['last_name'].', '.$row['first_name'], $item['action'], $item['how'], $row['data']['employee_id'] ?? ($item['generated_id'] ? "{$item['generated_id']} (new)" : ''),
                $row['station'], $item['branch_id'] ?? '', $item['problem'], implode(' | ', $row['warnings']),
            ]);
        }

        fclose($handle);
        chmod($path, 0600);

        return $path;
    }
}
