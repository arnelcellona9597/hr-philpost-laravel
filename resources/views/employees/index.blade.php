@php
    use App\Enums\BloodType;
    use App\Enums\CivilStatus;
    use App\Enums\Classification;
    use App\Enums\EducationalAttainment;
    use App\Enums\EmploymentStatus;
    use App\Enums\Gender;
    use App\Enums\Region;
    use App\Support\Badge;
    use App\Support\HrDate;

    $checks = [
        ['label' => 'Employment status', 'name' => 'status', 'cases' => EmploymentStatus::cases(), 'selected' => $search->status],
        ['label' => 'Classification', 'name' => 'classification', 'cases' => Classification::cases(), 'selected' => $search->classification],
        ['label' => 'Gender', 'name' => 'gender', 'cases' => Gender::cases(), 'selected' => $search->gender],
        ['label' => 'Civil status', 'name' => 'civilStatus', 'cases' => CivilStatus::cases(), 'selected' => $search->civilStatus],
        ['label' => 'Blood type', 'name' => 'bloodType', 'cases' => BloodType::cases(), 'selected' => $search->bloodType],
    ];
    $exportQuery = http_build_query(['module' => 'employees', ...request()->except('page')]);
@endphp

<x-layouts.app title="Employees" heading="Employee profiles" description="Search, filter and open any employee record.">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ url("/data/export?{$exportQuery}") }}"><x-icon name="download" /> Export these results</a>
        <a class="btn-ghost" href="{{ route('data.duplicates', ['module' => 'employees']) }}"><x-icon name="copy" /> Duplicates</a>
        <a class="btn-primary" href="{{ route('employees.create') }}"><x-icon name="plus" /> Add employee</a>
    </x-slot:actions>

    <form method="get" class="card mb-4 p-4" id="search">
        <div class="flex flex-wrap gap-3">
            <div class="relative min-w-64 flex-1">
                <label for="q" class="sr-only">Search</label>
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
                <input class="input pl-9" id="q" name="q" type="search" value="{{ $search->text }}" placeholder="Search name, employee ID, email, position, phone, SSS/TIN/PhilHealth/Pag-IBIG…" />
            </div>
            <button class="btn-primary">Search</button>
            @if ($search->text !== '' || $filters > 0)
                <a class="btn-ghost" href="{{ route('employees.index') }}">Clear all</a>
            @endif
        </div>

        <details class="mt-3" @if ($filters > 0) open @endif>
            <summary class="inline-flex cursor-pointer select-none items-center gap-2 text-sm font-medium text-fg-2 hover:text-fg">
                <x-icon name="filter" /> Advanced filters
                @if ($filters > 0)
                    <span class="badge-primary">{{ $filters }} active</span>
                @endif
            </summary>
            <div class="mt-4 grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($checks as $group)
                    <fieldset>
                        <legend class="label">{{ $group['label'] }}</legend>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            @foreach ($group['cases'] as $case)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="{{ $group['name'] }}[]" value="{{ $case->value }}" @checked(in_array($case->value, $group['selected'], true)) class="size-4 accent-[var(--primary)]" />
                                    {{ $case->value }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
                <div>
                    <label class="label" for="branch">Station / branch</label>
                    <select class="input" id="branch" name="branch[]" multiple size="4">
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected(in_array($branch->id, $search->branch, true))>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    <p class="field-help">Ctrl/⌘-click to pick several.</p>
                </div>
                <div>
                    <label class="label" for="region">Region</label>
                    <select class="input" id="region" name="region[]" multiple size="4">
                        @foreach (Region::cases() as $region)
                            <option @selected(in_array($region->value, $search->region, true))>{{ $region->value }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="education">Educational attainment</label>
                    <select class="input" id="education" name="education[]" multiple size="4">
                        @foreach (EducationalAttainment::cases() as $attainment)
                            <option @selected(in_array($attainment->value, $search->education, true))>{{ $attainment->value }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="position">Position contains</label>
                    <input class="input" id="position" name="position" value="{{ $search->position }}" placeholder="e.g. Engineer" />
                </div>
                <div class="md:col-span-2">
                    <span class="label">Date hired</span>
                    <div class="flex items-center gap-2">
                        <input class="input" type="date" name="hiredFrom" value="{{ $search->hiredFrom }}" aria-label="Hired from" />
                        <span class="text-muted">to</span>
                        <input class="input" type="date" name="hiredTo" value="{{ $search->hiredTo }}" aria-label="Hired to" />
                    </div>
                </div>
                <div>
                    <span class="label">Age (years)</span>
                    <div class="flex items-center gap-2">
                        <input class="input" type="number" min="0" max="120" name="ageMin" value="{{ $search->ageMin }}" aria-label="Minimum age" placeholder="min" />
                        <span class="text-muted">to</span>
                        <input class="input" type="number" min="0" max="120" name="ageMax" value="{{ $search->ageMax }}" aria-label="Maximum age" placeholder="max" />
                    </div>
                </div>
                <div>
                    <span class="label">Length of service (years)</span>
                    <div class="flex items-center gap-2">
                        <input class="input" type="number" min="0" max="80" name="losMin" value="{{ $search->losMin }}" aria-label="Minimum years of service" placeholder="min" />
                        <span class="text-muted">to</span>
                        <input class="input" type="number" min="0" max="80" name="losMax" value="{{ $search->losMax }}" aria-label="Maximum years of service" placeholder="max" />
                    </div>
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <button class="btn-primary">Apply filters</button>
                <a class="btn-ghost" href="{{ route('employees.index') }}">Reset</a>
            </div>
        </details>
        @if ($search->sort !== 'fullName')
            <input type="hidden" name="sort" value="{{ $search->sort }}" />
        @endif
    </form>

    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-muted">{{ count($columns) }} columns shown</p>
        <details class="relative">
            <summary class="btn-secondary btn-sm cursor-pointer list-none"><x-icon name="settings" /> Columns</summary>
            <form method="get" class="absolute right-0 z-20 mt-2 max-h-[70vh] w-72 overflow-y-auto rounded-xl border border-line bg-surface p-3 shadow-pop">
                @foreach (request()->except(['cols', 'page']) as $key => $value)
                    @foreach ((array) $value as $item)
                        @if (is_scalar($item))
                            <input type="hidden" name="{{ $key }}{{ is_array($value) ? '[]' : '' }}" value="{{ $item }}" />
                        @endif
                    @endforeach
                @endforeach
                <p class="mb-2 text-sm font-semibold">Columns to show</p>
                @foreach (collect($columnChoices)->groupBy('group', preserveKeys: true) as $group => $choices)
                    <fieldset class="mb-3">
                        <legend class="eyebrow mb-1">{{ $group }}</legend>
                        @foreach ($choices as $key => $choice)
                            <label class="flex items-center gap-2 py-0.5 text-sm">
                                <input type="checkbox" name="cols[]" value="{{ $key }}" class="size-4" @checked(in_array($key, $columns, true)) @disabled($key === 'name') />
                                {{ $choice['label'] }}
                            </label>
                        @endforeach
                    </fieldset>
                @endforeach
                <input type="hidden" name="cols[]" value="name" />
                <div class="sticky bottom-0 -mx-3 -mb-3 flex gap-2 border-t border-line bg-surface p-3">
                    <button class="btn-primary btn-sm">Apply</button>
                    <a class="btn-ghost btn-sm" href="{{ request()->url().'?'.http_build_query([...request()->except(['cols', 'page']), 'cols' => 'default']) }}">Reset to usual</a>
                </div>
            </form>
        </details>
    </div>

    <div class="card overflow-hidden">
        @if ($employees->total() === 0)
            <x-empty-state title="No employees match" :text="$search->text !== '' || $filters > 0 ? 'Try fewer filters or a different search.' : 'Add your first employee or import an Excel file.'">
                <a class="btn-primary" href="{{ route('employees.create') }}">Add employee</a>
                <a class="btn-secondary" href="{{ url('/data') }}">Import from Excel</a>
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            @foreach ($columns as $key)
                                @if ($columnChoices[$key]['sort'])
                                    <x-sort-header :field="$columnChoices[$key]['sort']" :label="$columnChoices[$key]['label']" />
                                @else
                                    <th scope="col">{{ $columnChoices[$key]['label'] }}</th>
                                @endif
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($employees as $employee)
                            <tr class="cursor-pointer" data-href="{{ route('employees.show', $employee) }}">
                                @foreach ($columns as $key)
                                    @switch($key)
                                        @case('employee_id')
                                            <td class="whitespace-nowrap font-mono text-xs text-fg-2">{{ $employee->employee_id }}</td>
                                            @break
                                        @case('name')
                                            <td>
                                                <a href="{{ route('employees.show', $employee) }}" class="flex items-center gap-3 font-medium hover:text-primary-ink">
                                                    <x-avatar :name="$employee->first_name.' '.$employee->last_name" :image-id="$employee->profile_picture_id" class="size-8 text-xs" />
                                                    <span>{{ $employee->full_name }}</span>
                                                </a>
                                            </td>
                                            @break
                                        @case('position')
                                            <td>{{ $employee->position }}</td>
                                            @break
                                        @case('station')
                                            <td class="text-fg-2">{{ $employee->station?->name }}</td>
                                            @break
                                        @case('classification')
                                            <td><span class="badge-neutral">{{ $employee->classification->value }}</span></td>
                                            @break
                                        @case('status')
                                            <td><span class="{{ Badge::for($employee->employment_status) }}">{{ $employee->employment_status->value }}</span></td>
                                            @break
                                        @case('date_hired')
                                            <td class="whitespace-nowrap text-fg-2 tabular">{{ HrDate::format($employee->date_hired) }}</td>
                                            @break
                                        @case('service')
                                            <td class="whitespace-nowrap text-fg-2">{{ $employee->length_of_service }}</td>
                                            @break
                                        @default
                                            <td class="text-fg-2">{{ $employee->fieldText($columnChoices[$key]['field']) }}</td>
                                    @endswitch
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$employees" />
        @endif
    </div>
</x-layouts.app>
