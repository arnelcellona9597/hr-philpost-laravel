<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds records that were probably entered twice, and removes the extra copies. A group is a set of
 * records that share the same person (name and birth date, an email, or a government number), or, for
 * applicants, the same name, position and date applied. Grouping runs in SQL, so it stays fast on large lists.
 *
 * @phpstan-type Group array{reason: string, ids: list<int>}
 */
class DuplicateFinder
{
    public const MODULES = ['employees' => 'Employees', 'applications' => 'Applicants'];

    private const MAX_GROUPS = 200;

    private const NUMBERS = ['tin' => 'TIN', 'sss' => 'SSS number', 'philhealth' => 'PhilHealth number', 'pagibig' => 'Pag-IBIG number'];

    /**
     * @return list<Group>
     */
    public function groups(string $module): array
    {
        $found = $module === 'employees' ? $this->employeeGroups() : $this->applicationGroups();

        return array_slice($this->merge($found), 0, self::MAX_GROUPS);
    }

    /**
     * Records that look like the same person as this one, not counting itself.
     *
     * @return list<int>
     */
    public function similarTo(Model $record): array
    {
        if ($record instanceof Employee) {
            $others = Employee::query()->whereKeyNot($record->id)->where(function ($query) use ($record): void {
                $query->where(fn ($same) => $same->whereRaw('LOWER(last_name) = ?', [mb_strtolower(trim($record->last_name))])->whereRaw('LOWER(first_name) = ?', [mb_strtolower(trim($record->first_name))])->whereNotNull('date_of_birth')->where('date_of_birth', $record->date_of_birth?->toDateString()));

                if (filled($record->email)) {
                    $query->orWhereRaw('LOWER(email) = ?', [mb_strtolower(trim($record->email))]);
                }

                foreach (array_keys(self::NUMBERS) as $column) {
                    if (filled($record->{$column})) {
                        $query->orWhere($column, $record->{$column});
                    }
                }
            });

            return $others->limit(10)->pluck('id')->all();
        }

        return Application::query()->whereKeyNot($record->id)
            ->whereRaw('LOWER(applicant_name) = ?', [mb_strtolower(trim($record->applicant_name))])
            ->whereRaw('LOWER(position_applied) = ?', [mb_strtolower(trim($record->position_applied))])
            ->where('date_applied', $record->date_applied?->toDateString())
            ->limit(10)->pluck('id')->all();
    }

    /**
     * The records of each group, oldest first.
     *
     * @param  list<Group>  $groups
     * @return Collection<int, Model>
     */
    public function records(string $module, array $groups): Collection
    {
        $ids = array_merge(...array_column($groups, 'ids') ?: [[]]);
        $query = $module === 'employees' ? Employee::query()->with('station') : Application::query()->with('station');

        return $query->whereIn('id', $ids)->orderBy('id')->get()->keyBy('id');
    }

    /**
     * Delete records, skipping any an employee still has linked history for.
     *
     * @param  list<int>  $ids
     * @return array{deleted: int, blocked: list<string>}
     */
    public function delete(string $module, array $ids, MediaStore $media): array
    {
        $deleted = 0;
        $blocked = [];
        $model = $module === 'employees' ? Employee::class : Application::class;

        foreach ($model::query()->whereIn('id', $ids)->get() as $record) {
            $blocker = $record instanceof Employee ? $record->deleteBlocker() : null;

            if ($blocker !== null) {
                $blocked[] = $record->full_name.': has linked records';

                continue;
            }

            $photo = $record instanceof Employee ? $record->profilePicture()->first() : null;
            DB::transaction(fn () => $record->delete());
            $photo !== null && $media->delete($photo);
            $deleted++;
        }

        return ['deleted' => $deleted, 'blocked' => $blocked];
    }

    /**
     * @return list<Group>
     */
    private function employeeGroups(): array
    {
        $groups = [];

        $named = Employee::query()->toBase()
            ->selectRaw('GROUP_CONCAT(id) AS ids')
            ->whereNotNull('date_of_birth')
            ->groupByRaw('LOWER(TRIM(last_name)), LOWER(TRIM(first_name)), date_of_birth')
            ->havingRaw('COUNT(*) > 1')
            ->limit(500)->pluck('ids');

        foreach ($named as $ids) {
            $groups[] = ['reason' => 'Same name and date of birth', 'ids' => $this->ids($ids)];
        }

        $emails = Employee::query()->toBase()->selectRaw('GROUP_CONCAT(id) AS ids')->whereNotNull('email')->where('email', '!=', '')->groupByRaw('LOWER(TRIM(email))')->havingRaw('COUNT(*) > 1')->limit(500)->pluck('ids');

        foreach ($emails as $ids) {
            $groups[] = ['reason' => 'Same email address', 'ids' => $this->ids($ids)];
        }

        foreach (self::NUMBERS as $column => $label) {
            $shared = Employee::query()->toBase()->selectRaw('GROUP_CONCAT(id) AS ids')->whereNotNull($column)->where($column, '!=', '')->groupBy($column)->havingRaw('COUNT(*) > 1')->limit(500)->pluck('ids');

            foreach ($shared as $ids) {
                $groups[] = ['reason' => "Same {$label}", 'ids' => $this->ids($ids)];
            }
        }

        return $groups;
    }

    /**
     * @return list<Group>
     */
    private function applicationGroups(): array
    {
        $same = Application::query()->toBase()
            ->selectRaw('GROUP_CONCAT(id) AS ids')
            ->groupByRaw('LOWER(TRIM(applicant_name)), LOWER(TRIM(position_applied)), date_applied')
            ->havingRaw('COUNT(*) > 1')
            ->limit(500)->pluck('ids');

        return array_map(fn (string $ids): array => ['reason' => 'Same name, position and date applied', 'ids' => $this->ids($ids)], $same->all());
    }

    /**
     * @return list<int>
     */
    private function ids(string $concat): array
    {
        $ids = array_map('intval', explode(',', $concat));
        sort($ids);

        return $ids;
    }

    /**
     * Join groups that share a record, so one person shows once with every reason.
     *
     * @param  list<Group>  $groups
     * @return list<Group>
     */
    private function merge(array $groups): array
    {
        $merged = [];

        foreach ($groups as $group) {
            $touching = array_keys(array_filter($merged, fn (array $other): bool => array_intersect($other['ids'], $group['ids']) !== []));
            $ids = $group['ids'];
            $reasons = [$group['reason']];

            foreach ($touching as $index) {
                $ids = array_merge($ids, $merged[$index]['ids']);
                $reasons = array_merge($reasons, explode('; ', $merged[$index]['reason']));
                unset($merged[$index]);
            }

            $ids = array_values(array_unique($ids));
            sort($ids);
            $merged[] = ['reason' => implode('; ', array_values(array_unique($reasons))), 'ids' => $ids];
        }

        usort($merged, fn (array $a, array $b): int => $a['ids'][0] <=> $b['ids'][0]);

        return array_values($merged);
    }
}
