<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Branch;
use App\Models\BranchUnit;
use App\Support\PageSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The stations, divisions, sections and units under each branch.
 */
class BranchUnitController extends Controller
{
    use RespondsToForms;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', BranchUnit::class);

        $search = Str::limit(trim((string) $request->query('q')), 100, '');
        $filter = (string) $request->query('branch');

        $units = BranchUnit::query()
            ->with('branch:id,name')
            ->withCount('employees')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($filter === 'none', fn ($query) => $query->whereNull('branch_id'))
            ->when(ctype_digit($filter), fn ($query) => $query->where('branch_id', (int) $filter))
            ->orderByRaw('branch_id IS NOT NULL')
            ->orderBy('name')
            ->paginate(PageSize::resolve($request, 50))
            ->withQueryString();

        return view('branches.units', [
            'units' => $units,
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'unplaced' => BranchUnit::query()->whereNull('branch_id')->count(),
            'search' => $search,
            'filter' => $filter,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', BranchUnit::class);

        [$name, $branchId, $problem] = $this->input($request);

        if ($problem !== null) {
            return $this->refused($problem);
        }

        BranchUnit::query()->create(['name' => $name, 'branch_id' => $branchId]);

        return $this->flash(redirect()->route('branches.units.index'), "Unit {$name} was added.");
    }

    public function update(Request $request, BranchUnit $unit): RedirectResponse
    {
        Gate::authorize('update', $unit);

        [$name, $branchId, $problem] = $this->input($request, $unit);

        if ($problem !== null) {
            return $this->refused($problem);
        }

        $unit->update(['name' => $name, 'branch_id' => $branchId]);

        return $this->flash(back(), "Unit {$name} was saved.");
    }

    public function destroy(BranchUnit $unit): RedirectResponse
    {
        Gate::authorize('delete', $unit);

        $blocker = $unit->deleteBlocker();

        if ($blocker !== null) {
            return $this->flash(back(), $blocker, 'error');
        }

        $unit->delete();

        return $this->flash(back(), "Unit {$unit->name} was deleted.");
    }

    /**
     * @return array{0: string, 1: int|null, 2: string|null} name, branch id, and why it cannot be saved
     */
    private function input(Request $request, ?BranchUnit $unit = null): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $request->input('name')));
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        if ($name === '' || mb_strlen($name) > 255) {
            return [$name, $branchId, 'Enter a unit name of up to 255 characters.'];
        }

        if ($branchId !== null && ! Branch::query()->whereKey($branchId)->exists()) {
            return [$name, $branchId, 'Choose a branch from the list.'];
        }

        $taken = BranchUnit::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->where(fn ($query) => $branchId === null ? $query->whereNull('branch_id') : $query->where('branch_id', $branchId))
            ->when($unit, fn ($query) => $query->whereKeyNot($unit->id))
            ->exists();

        return [$name, $branchId, $taken ? 'That unit already exists under this branch.' : null];
    }
}
