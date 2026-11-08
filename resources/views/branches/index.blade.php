@php
    use App\Enums\Classification;

    $scopeLabel = $showAll ? 'all employees (any status)' : 'active employees';
    $classifications = array_column(Classification::cases(), 'value');
    $spec = [
        'labels' => $rows->map(fn (array $row): string => $row['branch']->name)->all(),
        'series' => array_map(fn (string $label, int $index): array => [
            'label' => $label,
            'slot' => $index + 1,
            'data' => $rows->map(fn (array $row): int => $row['counts'][$label])->all(),
        ], $classifications, array_keys($classifications)),
        'stacked' => true,
        'horizontal' => true,
        'unit' => 'employees',
    ];
@endphp

<x-layouts.app title="Branches" heading="Company branches" :description="'Each branch\'s address and its '.$scopeLabel.' by classification.'">
    <x-slot:actions>
        <div class="flex rounded-lg border border-line bg-surface p-0.5 text-sm" role="group" aria-label="Count">
            <a href="{{ route('branches.index') }}" @class(['rounded-md px-3 py-1.5', 'bg-primary-soft font-medium text-primary-ink' => ! $showAll, 'text-fg-2' => $showAll])>Active only</a>
            <a href="{{ route('branches.index', ['scope' => 'all']) }}" @class(['rounded-md px-3 py-1.5', 'bg-primary-soft font-medium text-primary-ink' => $showAll, 'text-fg-2' => ! $showAll])>All statuses</a>
        </div>
        <a class="btn-secondary" href="{{ route('branches.units.index') }}"><x-icon name="building" /> Units</a>
        <a class="btn-primary" href="{{ route('branches.create') }}"><x-icon name="plus" /> Add branch</a>
    </x-slot:actions>

    @if ($rows->isEmpty())
        <div class="card">
            <x-empty-state title="No branches yet" text="Add the stations, divisions or units employees are assigned to.">
                <a class="btn-primary" href="{{ route('branches.create') }}">Add branch</a>
            </x-empty-state>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($rows as ['branch' => $branch, 'counts' => $counts])
                <a href="{{ route('branches.show', $branch) }}" class="card group flex flex-col p-5 transition-colors hover:border-primary-ink">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="truncate font-semibold group-hover:text-primary-ink">{{ $branch->name }}</h2>
                            <p class="mt-0.5 text-sm text-muted">{{ $branch->address ?: 'No address recorded' }}</p>
                        </div>
                        @if ($branch->code)
                            <span class="badge-neutral font-mono">{{ $branch->code }}</span>
                        @endif
                    </div>
                    <p class="mt-4 text-3xl font-semibold tabular">{{ $counts['total'] }}<span class="text-sm font-normal text-muted"> employees</span></p>
                    <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
                        @foreach ($classifications as $index => $classification)
                            <div class="rounded-lg bg-surface-2 px-2 py-2">
                                <dt class="flex items-center justify-center gap-1.5 text-xs text-muted">
                                    <span class="inline-block size-2 rounded-sm" style="background: var(--series-{{ $index + 1 }})"></span>
                                    {{ $classification }}
                                </dt>
                                <dd class="mt-0.5 text-lg font-semibold tabular">{{ $counts[$classification] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if ($branch->region)
                        <p class="mt-3 text-xs text-muted">{{ $branch->region->value }}{{ $branch->contact_number ? ' · '.$branch->contact_number : '' }}</p>
                    @endif
                </a>
            @endforeach
        </div>

        <div class="mt-6 grid gap-4 xl:grid-cols-3">
            <div class="xl:col-span-2">
                <x-charts.chart title="Employees per branch" :subtitle="'By classification — '.$scopeLabel" :spec="$spec" />
            </div>
            <section class="card p-5">
                <h2 class="text-sm font-semibold">Totals</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach ($classifications as $classification)
                        <div class="flex justify-between"><dt class="text-fg-2">{{ $classification }}</dt><dd class="font-medium tabular">{{ $totals[$classification] }}</dd></div>
                    @endforeach
                    <div class="flex justify-between border-t border-line pt-2"><dt class="font-medium">All branches</dt><dd class="font-semibold tabular">{{ $totals['total'] }}</dd></div>
                    @if ($unassigned['total'] > 0)
                        <div class="flex justify-between text-warning">
                            <dt><a class="hover:underline" href="{{ route('employees.index', ['status' => 'Active']) }}">Not assigned to a branch</a></dt>
                            <dd class="tabular">{{ $unassigned['total'] }}</dd>
                        </div>
                    @endif
                </dl>
            </section>
        </div>
    @endif
</x-layouts.app>
