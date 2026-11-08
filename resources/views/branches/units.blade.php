<x-layouts.app
    title="Units"
    heading="Stations, divisions and units"
    description="The sections under each branch. An employee's branch follows the unit they are placed in."
    :back="['href' => route('branches.index'), 'label' => 'Branches']"
>
    @if ($unplaced > 0)
        <p class="mb-4 rounded-lg bg-warning-soft px-4 py-3 text-sm text-warning">
            {{ number_format($unplaced) }} unit{{ $unplaced === 1 ? '' : 's' }} not placed under a branch yet.
            <a class="font-medium underline" href="{{ route('branches.units.index', ['branch' => 'none']) }}">Show them</a>, choose a branch for each and press Save. The people in a unit move to its branch.
        </p>
    @endif

    <form method="post" action="{{ route('branches.units.store') }}" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        @csrf
        <div class="min-w-64 flex-1">
            <label class="label" for="new-name">New unit</label>
            <input class="input" id="new-name" name="name" maxlength="255" value="{{ old('name') }}" placeholder="e.g. Accounting Section, AdFin Division" required />
        </div>
        <div class="w-72">
            <label class="label" for="new-branch">Branch</label>
            <select class="input" id="new-branch" name="branch_id">
                <option value="">Not placed yet</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn-primary"><x-icon name="plus" /> Add unit</button>
    </form>

    <form method="get" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-64 flex-1">
            <label class="label" for="q">Search units</label>
            <input class="input" id="q" name="q" type="search" value="{{ $search }}" />
        </div>
        <div class="w-72">
            <label class="label" for="branch-filter">Branch</label>
            <select class="input" id="branch-filter" name="branch">
                <option value="">All branches</option>
                <option value="none" @selected($filter === 'none')>Not placed yet</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($filter === (string) $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn-secondary">Filter</button>
    </form>

    <div class="card overflow-hidden">
        @if ($units->total() === 0)
            <x-empty-state title="No units" text="Add a unit above, or load them from the master file import." />
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Unit</th>
                            <th scope="col">Branch</th>
                            <th scope="col" class="text-right">Employees</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($units as $unit)
                            <tr>
                                <td class="min-w-72">
                                    <label class="sr-only" for="name-{{ $unit->id }}">Unit name</label>
                                    <input class="input py-1" id="name-{{ $unit->id }}" name="name" form="unit-{{ $unit->id }}" maxlength="255" value="{{ $unit->name }}" required />
                                </td>
                                <td class="min-w-64">
                                    <label class="sr-only" for="branch-{{ $unit->id }}">Branch</label>
                                    <select class="input py-1" id="branch-{{ $unit->id }}" name="branch_id" form="unit-{{ $unit->id }}">
                                        <option value="">Not placed yet</option>
                                        @foreach ($branches as $branch)
                                            <option value="{{ $branch->id }}" @selected($unit->branch_id === $branch->id)>{{ $branch->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="text-right tabular">{{ number_format($unit->employees_count) }}</td>
                                <td class="whitespace-nowrap text-right">
                                    <form id="unit-{{ $unit->id }}" method="post" action="{{ route('branches.units.update', $unit) }}" class="inline">
                                        @csrf
                                        @method('PUT')
                                        <button class="btn-secondary btn-sm">Save</button>
                                    </form>
                                    <form method="post" action="{{ route('branches.units.destroy', $unit) }}" class="inline" data-confirm="Delete the unit {{ $unit->name }}? Only possible when nobody is in it.">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn-ghost btn-sm text-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$units" />
        @endif
    </div>
</x-layouts.app>
