@php
    use App\Enums\ApplicationStatus;
    use App\Enums\OnboardingRequirement;
    use App\Enums\RequirementsCompletion;
    use App\Enums\RequirementStatus;
    use App\Support\Badge;
    use App\Support\HrDate;

    $short = fn (string $label): string => str_replace(['Verification', 'Declaration', 'Assumption of Duty'], ['Verif.', 'Decl.', 'Assumption'], $label);
    $exportQuery = http_build_query(['module' => 'applications', ...request()->except('page')]);
    $filtered = $text !== '' || $statuses !== [] || $requirements !== null || $station !== null || $position !== '' || $fdsFrom !== null || $fdsTo !== null;
@endphp

<x-layouts.app title="Onboarding Tracker" heading="Onboarding tracker" description="Applicants and their document requirements, plus who joined and left recently.">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ url("/data/export?{$exportQuery}") }}"><x-icon name="download" /> Export</a>
        <a class="btn-primary" href="{{ route('onboarding.create') }}"><x-icon name="plus" /> New application</a>
    </x-slot:actions>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        @foreach (ApplicationStatus::cases() as $status)
            <x-stat-card :label="$status->value" :value="(int) ($statusCounts[$status->value] ?? 0)" :href="route('onboarding.index', ['status' => $status->value])" />
        @endforeach
    </div>

    <section class="mt-6 grid gap-4 lg:grid-cols-4" aria-label="Workforce movement">
        <div class="card p-4">
            <p class="text-xs font-medium text-muted">Active employees</p>
            <p class="mt-1 text-3xl font-semibold tabular">{{ $activeCount }}</p>
            <a href="{{ route('employees.index', ['status' => 'Active']) }}" class="text-xs text-primary-ink hover:underline">View list</a>
        </div>
        <div class="card p-4 lg:col-span-2">
            <p class="text-xs font-medium text-muted">Newly hired (last 90 days) · {{ $newHireCount }}</p>
            @if ($newHires->isEmpty())
                <p class="mt-2 text-sm text-muted">No new hires.</p>
            @else
                <ul class="mt-2 divide-y divide-line text-sm">
                    @foreach ($newHires as $hire)
                        <li class="flex justify-between gap-3 py-1.5">
                            <a href="{{ route('employees.show', $hire) }}" class="truncate font-medium hover:text-primary-ink">{{ $hire->full_name }}</a>
                            <span class="shrink-0 text-muted tabular">{{ HrDate::format($hire->date_hired) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        <div class="card p-4">
            <p class="text-xs font-medium text-muted">Separated in {{ $year }}</p>
            <dl class="mt-2 space-y-1.5 text-sm">
                @foreach (['Resigned', 'Retired', 'Terminated'] as $separation)
                    <div class="flex justify-between">
                        <dt><a class="hover:text-primary-ink" href="{{ route('employees.index', ['status' => $separation, 'sort' => '-dateHired']) }}">{{ $separation }}</a></dt>
                        <dd class="font-semibold tabular">{{ (int) ($separated[$separation] ?? 0) }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    <form method="get" class="card mt-6 flex flex-wrap items-end gap-3 p-4" data-autosubmit>
        <div class="min-w-56 flex-1">
            <label class="label" for="q">Search</label>
            <input class="input" id="q" name="q" type="search" value="{{ $text }}" placeholder="Applicant, application ID, position, replacement…" />
        </div>
        <div>
            <label class="label" for="status">Application status</label>
            <select class="input" id="status" name="status">
                <option value="">All</option>
                @foreach (ApplicationStatus::cases() as $status)
                    <option @selected(in_array($status->value, $statuses, true))>{{ $status->value }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="requirements">Requirements</label>
            <select class="input" id="requirements" name="requirements">
                <option value="">All</option>
                @foreach (RequirementsCompletion::cases() as $completion)
                    <option @selected($requirements === $completion)>{{ $completion->value }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="station">Station</label>
            <select class="input" id="station" name="station">
                <option value="">All</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($branch->id === $station)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="position">Position</label>
            <select class="input" id="position" name="position">
                <option value="">All</option>
                @foreach ($positions as $option)
                    <option @selected($option === $position)>{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="fdsFrom">FDS from</label>
            <input class="input" id="fdsFrom" name="fdsFrom" type="date" value="{{ $fdsFrom }}" />
        </div>
        <div>
            <label class="label" for="fdsTo">FDS to</label>
            <input class="input" id="fdsTo" name="fdsTo" type="date" value="{{ $fdsTo }}" />
        </div>
        <button class="btn-primary">Filter</button>
        @if ($filtered)
            <a class="btn-ghost" href="{{ route('onboarding.index') }}">Clear</a>
        @endif
    </form>

    <div class="card mt-4 overflow-hidden">
        @if ($applications->total() === 0)
            <x-empty-state title="No applications found" text="Add an applicant to start tracking requirements.">
                <a class="btn-primary" href="{{ route('onboarding.create') }}">New application</a>
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table text-xs">
                    <thead>
                        <tr>
                            <x-sort-header field="applicationId" label="App ID" />
                            <x-sort-header field="applicantName" label="Applicant" />
                            <x-sort-header field="positionApplied" label="Position" />
                            <th>Station</th>
                            <x-sort-header field="dateApplied" label="Applied" />
                            <x-sort-header field="applicationStatus" label="Status" />
                            @foreach (OnboardingRequirement::cases() as $requirement)
                                <th class="!normal-case" title="{{ $requirement->label() }}">{{ $short($requirement->label()) }}</th>
                            @endforeach
                            <x-sort-header field="dateSubmitted" label="Submitted" />
                            <x-sort-header field="fdsDate" label="FDS" />
                            <x-sort-header field="requirementsStatus" label="Requirements" />
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($applications as $application)
                            <tr data-app="{{ $application->id }}">
                                <td class="whitespace-nowrap font-mono text-fg-2"><a class="hover:text-primary-ink" href="{{ route('onboarding.show', $application) }}">{{ $application->application_id }}</a></td>
                                <td class="min-w-36 text-sm">
                                    <a class="font-medium hover:text-primary-ink" href="{{ route('onboarding.show', $application) }}">{{ $application->applicant_name }}</a>
                                    @if ($application->replacement)
                                        <span class="block text-xs text-muted">Replaces {{ $application->replacement }}</span>
                                    @endif
                                </td>
                                <td class="min-w-32">{{ $application->position_applied }}</td>
                                <td class="min-w-28 text-fg-2">{{ $application->station?->name }}</td>
                                <td class="whitespace-nowrap tabular text-fg-2">{{ HrDate::format($application->date_applied) }}</td>
                                <td class="whitespace-nowrap">
                                    <span class="{{ Badge::for($application->application_status) }}">{{ $application->application_status->value }}</span>
                                    @if ($application->requirements_email_sent_at)
                                        <span class="ml-1 inline-flex align-middle text-success" title="Requirements email sent to {{ $application->requirements_email_to }} on {{ HrDate::format($application->requirements_email_sent_at) }}">
                                            <x-icon name="mail" class="size-3.5" label="Requirements email sent" />
                                        </span>
                                    @endif
                                </td>
                                @foreach (OnboardingRequirement::cases() as $requirement)
                                    @php($current = $application->{$requirement->value}->value)
                                    <td class="!px-1.5">
                                        <select @class(['cursor-pointer rounded-full border-0 py-0.5 pl-2 pr-1 text-xs font-medium', Badge::REQUIREMENT[$current]]) data-req="{{ $requirement->value }}" aria-label="{{ $requirement->label() }} for {{ $application->applicant_name }}">
                                            @foreach (RequirementStatus::cases() as $option)
                                                <option @selected($option->value === $current)>{{ $option->value }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                @endforeach
                                <td class="whitespace-nowrap tabular text-fg-2">{{ HrDate::format($application->date_submitted) }}</td>
                                <td class="whitespace-nowrap tabular text-fg-2">{{ HrDate::format($application->fds_date) }}</td>
                                <td data-fds>
                                    <span class="{{ Badge::for($application->requirements_status) }}">{{ $application->requirements_status === RequirementsCompletion::Complete ? 'Complete' : "{$application->requirements_missing} missing" }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$applications" />
        @endif
    </div>
    <p class="mt-2 text-xs text-muted">Change a requirement directly in the table — it saves immediately. FDS status is Complete when every document is Submitted, Verified or N/A.</p>
</x-layouts.app>
