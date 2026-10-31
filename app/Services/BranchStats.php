<?php

namespace App\Services;

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use Illuminate\Support\Facades\DB;

/**
 * Employees per branch and classification.
 */
class BranchStats
{
    /**
     * @return array{byBranch: array<int, array<string, int>>, unassigned: array<string, int>}
     */
    public function counts(bool $activeOnly): array
    {
        $rows = DB::table('employees')
            ->selectRaw('station_id, classification, COUNT(*) AS total')
            ->when($activeOnly, fn ($query) => $query->where('employment_status', EmploymentStatus::Active->value))
            ->groupBy('station_id', 'classification')
            ->get();

        $blank = [...array_fill_keys(array_column(Classification::cases(), 'value'), 0), 'total' => 0];
        $byBranch = [];
        $unassigned = $blank;

        foreach ($rows as $row) {
            if ($row->station_id === null) {
                $unassigned[$row->classification] = (int) $row->total;
                $unassigned['total'] += (int) $row->total;

                continue;
            }

            $byBranch[$row->station_id] ??= $blank;
            $byBranch[$row->station_id][$row->classification] = (int) $row->total;
            $byBranch[$row->station_id]['total'] += (int) $row->total;
        }

        return ['byBranch' => $byBranch, 'unassigned' => $unassigned];
    }
}
