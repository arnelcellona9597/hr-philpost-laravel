<?php

namespace App\Http\Controllers;

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Branch;
use App\Services\BranchStats;
use App\Support\ImportModules;
use App\Support\RecordForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BranchController extends Controller
{
    use RespondsToForms;

    private const UNIQUE = ['name' => 'That branch name is already used.', 'code' => 'That code is already used by another branch.'];

    public function index(Request $request, BranchStats $stats): View
    {
        Gate::authorize('viewAny', Branch::class);

        $showAll = $request->query('scope') === 'all';
        $counts = $stats->counts(! $showAll);
        $blank = [...array_fill_keys(array_column(Classification::cases(), 'value'), 0), 'total' => 0];
        $rows = Branch::query()->orderBy('name')->get()->map(fn (Branch $branch): array => ['branch' => $branch, 'counts' => $counts['byBranch'][$branch->id] ?? $blank]);

        return view('branches.index', [
            'showAll' => $showAll,
            'rows' => $rows,
            'totals' => array_map(fn (string $key): int => $rows->sum(fn (array $row): int => $row['counts'][$key]), array_combine(array_keys($blank), array_keys($blank))),
            'unassigned' => $counts['unassigned'],
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Branch::class);

        return view('branches.form', ['branch' => null, 'fields' => $this->fields(), 'values' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Branch::class);

        ['data' => $data, 'errors' => $errors] = RecordForm::parse($request, $this->fields(), 'branches', self::UNIQUE);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $branch = Branch::query()->create($data);

        return $this->flash(redirect()->route('branches.show', $branch), "Branch {$branch->name} was added.");
    }

    public function show(Branch $branch): View
    {
        Gate::authorize('view', $branch);

        $employees = $branch->employees()->orderBy('employment_status')->orderBy('full_name')->limit(2000)->get();
        $active = $employees->where('employment_status', EmploymentStatus::Active);

        return view('branches.show', ['branch' => $branch, 'employees' => $employees, 'active' => $active]);
    }

    public function edit(Branch $branch): View
    {
        Gate::authorize('update', $branch);

        return view('branches.form', ['branch' => $branch, 'fields' => $this->fields(), 'values' => RecordForm::values($branch, $this->fields())]);
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('update', $branch);

        ['data' => $data, 'errors' => $errors] = RecordForm::parse($request, $this->fields(), 'branches', self::UNIQUE, $branch);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $branch->update($data);

        return $this->flash(redirect()->route('branches.show', $branch), "Branch {$branch->name} was updated.");
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        Gate::authorize('delete', $branch);

        $blocker = $branch->deleteBlocker();

        if ($blocker !== null) {
            return $this->flash(redirect()->route('branches.show', $branch), $blocker, 'error');
        }

        $branch->delete();

        return $this->flash(redirect()->route('branches.index'), "Branch {$branch->name} was deleted.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        return ImportModules::find('branches')['fields'];
    }
}
