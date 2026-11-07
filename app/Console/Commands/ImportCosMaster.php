<?php

namespace App\Console\Commands;

use App\Services\CosMasterImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

#[Signature('hr:import-cos-master {file : The COS Master File workbook (.xlsx)} {--commit : Save the changes. Without it nothing is changed} {--stations= : CSV of "text in the file,office name" to fix how stations are named} {--force : With --commit, do not ask first}')]
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
            $import = new CosMasterImport($this->stationOverrides());
            $rows = $import->read($path);
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

        $this->info(sprintf('Done: %d employees updated, %d added, %d branches created, %d employment periods written.', $result['updated'], $result['created'], $result['branches'], $result['periods']));

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function stationOverrides(): array
    {
        $file = (string) $this->option('stations');

        if ($file === '') {
            return [];
        }

        if (! is_file($file)) {
            throw new RuntimeException("No stations file at {$file}.");
        }

        $map = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$text, $office] = array_pad(str_getcsv($line), 2, '');

            if (trim($text) !== '' && trim($office) !== '') {
                $map[mb_strtolower(trim($text))] = trim($office);
            }
        }

        return $map;
    }

    /**
     * @param  array{items: list<array<string, mixed>>, stations: array<string, array{canonical: string, exists: bool, people: int}>, counts: array<string, int>, missing: list<string>}  $plan
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

        $new = collect($plan['stations'])->reject(fn (array $station): bool => $station['exists'])->groupBy('canonical')->map(fn ($group, string $name): array => [$name, $group->sum('people')])->values()->all();
        $this->line(sprintf('%d office names in the file; %d are not branches yet and would be created:', count(collect($plan['stations'])->pluck('canonical')->unique()), count($new)));
        $this->table(['New branch', 'People'], $new);

        $warnings = collect($plan['items'])->sum(fn (array $item): int => count($item['row']['warnings']));
        $this->line("{$warnings} warnings (values left unchanged); every one is in the report.");
        $this->line("Report (contains personal data, delete it afterwards): {$report}");
        $this->line('Office names, one line per way the file writes a station: '.$this->writeStations($plan));
        $this->line('Fix any name in that file, then run again with --stations=<that file> to use your names.');
    }

    /**
     * @param  array{stations: array<string, array{canonical: string, exists: bool, people: int}>}  $plan
     */
    private function writeStations(array $plan): string
    {
        $path = storage_path('app/private/imports/cos-master-stations-'.now()->format('Ymd-His').'.csv');
        $handle = fopen($path, 'w');

        foreach ($plan['stations'] as $text => $station) {
            fputcsv($handle, [$text, $station['canonical'], $station['exists'] ? 'branch exists' : 'new branch', $station['people']]);
        }

        fclose($handle);
        chmod($path, 0600);

        return $path;
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
        fputcsv($handle, ['Line', 'Name', 'Result', 'Matched by', 'Employee ID given', 'Station in the file', 'Station used', 'Why skipped', 'Warnings']);

        foreach ($plan['items'] as $item) {
            $row = $item['row'];
            fputcsv($handle, [
                $row['line'], $row['last_name'].', '.$row['first_name'], $item['action'], $item['how'], $row['data']['employee_id'] ?? ($item['generated_id'] ? "{$item['generated_id']} (new)" : ''),
                $row['station'], $item['station'], $item['problem'], implode(' | ', $row['warnings']),
            ]);
        }

        fclose($handle);
        chmod($path, 0600);

        return $path;
    }
}
