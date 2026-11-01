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
                <div>
                    <label class="label" for="limit">Rows per page</label>
                    <select class="input" id="limit" name="limit">
                        @foreach ([25, 50, 100] as $size)
                            <option value="{{ $size }}" @selected($size === $search->limit)>{{ $size }}</option>
                        @endforeach
                    </select>
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
                            <x-sort-header field="employeeId" label="Employee ID" />
                            <x-sort-header field="fullName" label="Name" />
                            <x-sort-header field="position" label="Position" />
                            <th scope="col">Station</th>
                            <x-sort-header field="classification" label="Classification" />
                            <x-sort-header field="employmentStatus" label="Status" />
                            <x-sort-header field="dateHired" label="Date hired" />
                            <th scope="col">Service</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($employees as $employee)
                            <tr class="cursor-pointer" data-href="{{ route('employees.show', $employee) }}">
                                <td class="whitespace-nowrap font-mono text-xs text-fg-2">{{ $employee->employee_id }}</td>
                                <td>
                                    <a href="{{ route('employees.show', $employee) }}" class="flex items-center gap-3 font-medium hover:text-primary-ink">
                                        <x-avatar :name="$employee->first_name.' '.$employee->last_name" :image-id="$employee->profile_picture_id" class="size-8 text-xs" />
                                        <span>{{ $employee->full_name }}</span>
                                    </a>
                                </td>
                                <td>{{ $employee->position }}</td>
                                <td class="text-fg-2">{{ $employee->station?->name }}</td>
                                <td><span class="badge-neutral">{{ $employee->classification->value }}</span></td>
                                <td><span class="{{ Badge::for($employee->employment_status) }}">{{ $employee->employment_status->value }}</span></td>
                                <td class="whitespace-nowrap text-fg-2 tabular">{{ HrDate::format($employee->date_hired) }}</td>
                                <td class="whitespace-nowrap text-fg-2">{{ $employee->length_of_service }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$employees" />
        @endif
    </div>
</x-layouts.app>
