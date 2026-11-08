@php
    use App\Enums\Classification;
    use App\Support\Badge;
    use App\Support\HrDate;
@endphp

<x-layouts.app
    :title="$branch->name"
    :description="collect([$branch->address, $branch->region?->value, $branch->contact_number])->filter()->implode(' · ')"
    :back="['href' => route('branches.index'), 'label' => 'Branches']"
>
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('employees.index', ['branch' => $branch->id]) }}"><x-icon name="search" /> Search employees here</a>
        <a class="btn-primary" href="{{ route('branches.edit', $branch) }}"><x-icon name="edit" /> Edit</a>
        <form method="post" action="{{ route('branches.destroy', $branch) }}" data-confirm="Delete {{ $branch->name }}? Only possible when no employees or applicants are assigned.">
            @csrf
            @method('DELETE')
            <button class="btn-danger"><x-icon name="trash" /> Delete</button>
        </form>
    </x-slot:actions>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-stat-card label="Active employees" :value="$active->count()" tone="success" />
        @foreach (Classification::cases() as $classification)
            <x-stat-card :label="$classification->value.' (active)'" :value="$active->where('classification', $classification)->count()" />
        @endforeach
    </div>
    @if ($branch->notes)
        <p class="card mt-4 p-4 text-sm text-fg-2">{{ $branch->notes }}</p>
    @endif

    <section class="card mt-4 p-4">
        <div class="mb-2 flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold">Stations, divisions and units</h2>
            <a class="btn-ghost btn-sm" href="{{ route('branches.units.index', ['branch' => $branch->id]) }}">Manage units</a>
        </div>
        @if ($branch->units->isEmpty())
            <p class="text-sm text-muted">No units under this branch yet.</p>
        @else
            <ul class="grid gap-1 text-sm sm:grid-cols-2">
                @foreach ($branch->units as $unit)
                    <li class="flex justify-between gap-3 rounded-lg px-2 py-1 hover:bg-surface-2"><span>{{ $unit->name }}</span><span class="text-muted tabular">{{ number_format($unit->employees_count) }}</span></li>
                @endforeach
            </ul>
        @endif
    </section>

    <div class="card mt-6 overflow-hidden">
        <header class="p-4"><h2 class="text-sm font-semibold">Assigned employees ({{ $employees->count() }})</h2></header>
        @if ($employees->isEmpty())
            <p class="px-4 pb-6 text-sm text-muted">No employees are assigned to this branch.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Employee ID</th><th>Name</th><th>Position</th><th>Classification</th><th>Status</th><th>Date hired</th></tr></thead>
                    <tbody>
                        @foreach ($employees as $employee)
                            <tr>
                                <td class="font-mono text-xs text-fg-2">{{ $employee->employee_id }}</td>
                                <td><a class="font-medium hover:text-primary-ink" href="{{ route('employees.show', $employee) }}">{{ $employee->full_name }}</a></td>
                                <td>{{ $employee->position }}</td>
                                <td><span class="badge-neutral">{{ $employee->classification->value }}</span></td>
                                <td><span class="{{ Badge::for($employee->employment_status) }}">{{ $employee->employment_status->value }}</span></td>
                                <td class="whitespace-nowrap tabular text-fg-2">{{ HrDate::format($employee->date_hired) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-layouts.app>
