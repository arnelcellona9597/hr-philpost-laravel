<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('hr:undo-import-branches {--since= : The day (or date and time) of the import, as in the Audit Log, e.g. 2026-11-08} {--commit : Save the changes. Without it nothing is changed}')]
#[Description('Put employees back on the branches they had before the first COS Master File import, and remove the branches it created')]
class UndoCosMasterBranches extends Command
{
    /**
     * Execute the console command.
     *
     * The first import turned each station text into a branch and moved employees onto them. The Audit Log kept
     * where every employee was before, so this moves them back, but only for changes made by the import itself (it runs
     * from the command line, so the Audit Log shows no person) and only where the employee still sits on what it set.
     * Changes people made in the website are left alone. Then it deletes the branches the import created that nobody uses.
     */
    public function handle(): int
    {
        $since = trim((string) $this->option('since'));

        if ($since === '' || strtotime($since) === false) {
            $this->error('Say when the import ran, e.g. --since=2026-11-08');

            return self::FAILURE;
        }

        $created = AuditLog::query()->where('collection_slug', 'branches')->where('action', 'create')->whereNull('user_id')->where('created_at', '>=', $since)->pluck('doc_id')->map(fn (string $id): int => (int) $id)->all();
        $moves = $this->moves($since, $created);
        $branchesToDelete = $this->unusedAfter($created, $moves);

        $this->table(['What', 'Count'], [
            ['Branches created by the import', count($created)],
            ['Employees to put back on their earlier branch (or none)', count($moves)],
            ['Created branches that nobody will use afterwards (deleted)', count($branchesToDelete)],
            ['Created branches still used by someone else (kept)', count($created) - count($branchesToDelete)],
        ]);

        if (! $this->option('commit')) {
            $this->info('Nothing was changed. Run again with --commit to save.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Save these changes? Take a backup first (php8.5 artisan hr:backup).')) {
            $this->warn('Nothing was changed.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($moves, $branchesToDelete): void {
            foreach ($moves as $employeeId => $branchId) {
                Employee::query()->whereKey($employeeId)->first()?->forceFill(['station_id' => $branchId])->save();
                EmploymentPeriod::query()->where('employee_id', $employeeId)->update(['station_id' => $branchId]);
            }

            Branch::query()->whereIn('id', $branchesToDelete)->get()->each->delete();
        });

        $this->info(sprintf('Done: %d employees put back, %d branches removed.', count($moves), count($branchesToDelete)));

        return self::SUCCESS;
    }

    /**
     * employee id => branch id they should be on (null for none).
     *
     * @param  list<int>  $created
     * @return array<int, int|null>
     */
    private function moves(string $since, array $created): array
    {
        $entries = AuditLog::query()->where('collection_slug', 'employees')->whereIn('action', ['create', 'update'])->whereNull('user_id')->where('created_at', '>=', $since)->orderBy('id')->get();
        $firstFrom = [];
        $lastTo = [];
        $wasCreated = [];

        foreach ($entries as $entry) {
            $id = (int) $entry->doc_id;

            if ($entry->action === 'create') {
                $wasCreated[$id] = true;

                continue;
            }

            $change = $entry->changes['station_id'] ?? null;

            if ($change !== null) {
                $firstFrom[$id] = array_key_exists($id, $firstFrom) ? $firstFrom[$id] : $change['from'];
                $lastTo[$id] = $change['to'];
            }
        }

        $current = Employee::query()->whereIn('id', array_unique([...array_keys($firstFrom), ...array_keys($wasCreated)]))->pluck('station_id', 'id');
        $moves = [];

        foreach ($current as $id => $stationId) {
            if (array_key_exists($id, $firstFrom) && $stationId === ($lastTo[$id] === null ? null : (int) $lastTo[$id])) {
                $moves[$id] = $firstFrom[$id] === null ? null : (int) $firstFrom[$id];
            } elseif (isset($wasCreated[$id]) && in_array($stationId, $created, true)) {
                $moves[$id] = null;
            }
        }

        return array_filter($moves, fn (?int $branchId, int $id): bool => $branchId !== $current[$id], ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The created branches that nobody (employee, period or applicant) would use once the moves are made.
     *
     * @param  list<int>  $created
     * @param  array<int, int|null>  $moves
     * @return list<int>
     */
    private function unusedAfter(array $created, array $moves): array
    {
        $unused = [];

        foreach (Branch::query()->whereIn('id', $created)->pluck('id') as $branchId) {
            $stays = Employee::query()->where('station_id', $branchId)->whereNotIn('id', array_keys($moves))->exists()
                || Application::query()->where('station_id', $branchId)->exists()
                || EmploymentPeriod::query()->where('station_id', $branchId)->whereNotIn('employee_id', array_keys($moves))->exists();

            if (! $stays) {
                $unused[] = (int) $branchId;
            }
        }

        return $unused;
    }
}
