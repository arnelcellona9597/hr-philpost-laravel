<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\ContractScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ContractScopeController extends Controller
{
    use RespondsToForms;

    private const MAX_ITEMS = 40;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ContractScope::class);

        $search = mb_substr(trim((string) $request->query('q')), 0, 100);

        return view('contracts.scopes.index', [
            'scopes' => ContractScope::query()
                ->withCount('contracts')
                ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('position', 'like', '%'.addcslashes($search, '%_\\').'%'))
                ->orderBy('name')
                ->get(),
            'search' => $search,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', ContractScope::class);

        return view('contracts.scopes.form', ['scope' => new ContractScope(['active' => true, 'items' => []])]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ContractScope::class);

        $scope = ContractScope::query()->create($this->validated($request));

        return $this->flash(redirect()->route('contracts.scopes.index'), "Scope “{$scope->name}” was added.");
    }

    public function edit(ContractScope $scope): View
    {
        Gate::authorize('update', $scope);

        return view('contracts.scopes.form', ['scope' => $scope]);
    }

    public function update(Request $request, ContractScope $scope): RedirectResponse
    {
        Gate::authorize('update', $scope);

        $scope->update($this->validated($request, $scope));

        return $this->flash(redirect()->route('contracts.scopes.index'), "Scope “{$scope->name}” was saved. New contracts use it; issued ones keep theirs.");
    }

    public function duplicate(ContractScope $scope): RedirectResponse
    {
        Gate::authorize('create', ContractScope::class);

        $name = mb_substr($scope->name, 0, 110).' (copy)';

        for ($number = 2; ContractScope::query()->where('name', $name)->exists(); $number++) {
            $name = mb_substr($scope->name, 0, 105)." (copy {$number})";
        }

        $copy = $scope->replicate(['key']);
        $copy->name = $name;
        $copy->save();

        return $this->flash(redirect()->route('contracts.scopes.edit', $copy), "Copied to “{$copy->name}”.");
    }

    public function destroy(ContractScope $scope): RedirectResponse
    {
        Gate::authorize('delete', $scope);

        $scope->delete();

        return $this->flash(redirect()->route('contracts.scopes.index'), "Scope “{$scope->name}” was deleted. Issued contracts keep their text.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ContractScope $scope = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('contract_scopes', 'name')->ignore($scope)],
            'position' => ['nullable', 'string', 'max:150'],
            'office' => ['nullable', 'string', 'max:160'],
            'place_of_service' => ['nullable', 'string', 'max:160'],
            'items' => ['required', 'string', 'max:6000'],
        ]);

        $items = array_values(array_filter(array_map(trim(...), preg_split('/\R/', $data['items']))));

        return [...$data, 'items' => array_slice($items, 0, self::MAX_ITEMS), 'active' => $request->boolean('active')];
    }
}
