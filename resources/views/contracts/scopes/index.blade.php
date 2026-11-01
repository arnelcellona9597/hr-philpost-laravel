<x-layouts.app title="Scopes of service" heading="Scopes of service" :back="['href' => route('contracts.index'), 'label' => 'Contracts']" description="The duties for each kind of work. The contract's clauses stay in one template, so changing a clause never means editing every scope.">
    <x-slot:actions>
        <a class="btn-primary" href="{{ route('contracts.scopes.create') }}"><x-icon name="plus" /> New scope</a>
    </x-slot:actions>

    <form method="get" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-56 flex-1">
            <label class="label" for="q">Search</label>
            <input class="input" id="q" name="q" type="search" value="{{ $search }}" placeholder="Name or position" />
        </div>
        <button class="btn-secondary"><x-icon name="search" /> Search</button>
    </form>

    <div class="card overflow-hidden">
        @if ($scopes->isEmpty())
            <x-empty-state title="No scopes found" text="Add the duties for a kind of work." />
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Scope</th><th>Position</th><th class="hidden lg:table-cell">Office</th><th class="text-right">Duties</th><th class="text-right">Contracts</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($scopes as $scope)
                            <tr>
                                <td class="font-medium"><a class="text-primary-ink hover:underline" href="{{ route('contracts.scopes.edit', $scope) }}">{{ $scope->name }}</a></td>
                                <td class="text-fg-2">{{ $scope->position }}</td>
                                <td class="hidden max-w-xs truncate text-fg-2 lg:table-cell" title="{{ $scope->office }}">{{ $scope->office ?: 'Set per person' }}</td>
                                <td class="text-right tabular">{{ count($scope->items) }}</td>
                                <td class="text-right tabular">{{ $scope->contracts_count }}</td>
                                <td><span class="{{ $scope->active ? 'badge-success' : 'badge-neutral' }}">{{ $scope->active ? 'Active' : 'Inactive' }}</span></td>
                                <td class="text-right">
                                    <form method="post" action="{{ route('contracts.scopes.duplicate', $scope) }}">@csrf<button class="btn-ghost btn-sm"><x-icon name="copy" class="size-3.5" /> Duplicate</button></form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-layouts.app>
