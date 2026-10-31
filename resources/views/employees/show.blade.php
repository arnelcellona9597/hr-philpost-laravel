@php
    use App\Support\Badge;
    use App\Support\Deadlines;
    use App\Support\HrDate;
    use Illuminate\Support\Str;

    $sections = collect($fields)->groupBy('section');
    $display = function (array $field) use ($employee): string {
        $value = match ($field['kind']) {
            'branch' => $employee->station?->name,
            default => $employee->getAttribute($field['name']),
        };

        return match (true) {
            $value === null || $value === '' => '—',
            $field['kind'] === 'date' => HrDate::format($value),
            $value instanceof BackedEnum => $value->value,
            default => (string) $value,
        };
    };
    $timely = fn ($record): array => Deadlines::timeliness($record->date_submitted, Deadlines::for($deadlineSettings, $record::SLUG, $record->year, $record->ratingPeriodValue()));
    $short = ['on-time' => 'On time', 'late' => 'Late', 'overdue' => 'Overdue', 'pending' => 'Pending', 'submitted' => '✓'];
    $describe = fn ($record): string => ($record->date_submitted ? 'Submitted '.HrDate::format($record->date_submitted) : 'Not submitted').' · '.$timely($record)['label'];
@endphp

<x-layouts.app :title="$employee->full_name" :heading="$employee->full_name" :back="['href' => route('employees.index'), 'label' => 'Employees']">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ url("/messages/new?employees={$employee->id}") }}"><x-icon name="mail" /> Email employee</a>
        <a class="btn-secondary" href="{{ route('leave.create', ['employee' => $employee->id]) }}"><x-icon name="calendar" /> File leave</a>
        <a class="btn-secondary" href="{{ url("/certificates/new?employee={$employee->id}") }}"><x-icon name="award" /> Issue certificate</a>
        <a class="btn-primary" href="{{ route('employees.edit', $employee) }}"><x-icon name="edit" /> Edit</a>
        <form method="post" action="{{ route('employees.destroy', $employee) }}" data-confirm="Delete {{ $employee->full_name }}? This cannot be undone. Consider setting the status to Resigned/Retired/Terminated instead.">
            @csrf
            @method('DELETE')
            <button class="btn-danger"><x-icon name="trash" /> Delete</button>
        </form>
    </x-slot:actions>

    <div class="card mb-6 flex flex-wrap items-center gap-5 p-5">
        @if ($employee->profile_picture_id)
            <img src="{{ url("/files/{$employee->profile_picture_id}") }}" alt="Profile picture of {{ $employee->full_name }}" class="size-24 rounded-2xl object-cover" />
        @else
            <div class="flex size-24 items-center justify-center rounded-2xl bg-primary-soft text-2xl font-semibold text-primary-ink">{{ \App\Support\Names::initials($employee->first_name.' '.$employee->last_name) }}</div>
        @endif
        <div class="min-w-0 flex-1">
            <p class="font-mono text-sm text-muted">{{ $employee->employee_id }}</p>
            <p class="text-lg font-semibold">{{ $employee->position ?: 'No position recorded' }}</p>
            <p class="text-sm text-fg-2">{{ $employee->station?->name ?? 'No station' }}{{ $employee->region ? ' · '.$employee->region->value : '' }}</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <span class="{{ Badge::for($employee->employment_status) }}">{{ $employee->employment_status->value }}</span>
                <span class="badge-neutral">{{ $employee->classification->value }}</span>
                @if ($employee->length_of_service !== '')
                    <span class="badge-neutral">{{ $employee->length_of_service }} in service</span>
                @endif
                @if ($employee->age !== null)
                    <span class="badge-neutral">{{ $employee->age }} years old</span>
                @endif
            </div>
        </div>
        <div class="rounded-xl bg-surface-2 px-4 py-3 text-center">
            <p class="text-xs text-muted">Wellness leave {{ $year }}</p>
            <p class="text-2xl font-semibold tabular">{{ $remaining }}<span class="text-sm font-normal text-muted"> / {{ $allowance }} left</span></p>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            @foreach ($sections as $section => $sectionFields)
                <section class="card p-5">
                    <h2 class="mb-4 text-sm font-semibold">{{ $section }}</h2>
                    <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($sectionFields as $field)
                            <div @class(['sm:col-span-2 lg:col-span-3' => $field['kind'] === 'textarea' || Str::contains($field['name'], 'address')])>
                                <dt class="text-xs text-muted">{{ $field['label'] }}</dt>
                                <dd @class(['mt-0.5 text-sm', 'font-mono' => $field['kind'] === 'govId'])>{{ $display($field) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endforeach
        </div>

        <div class="space-y-6">
            <section class="card overflow-hidden">
                <header class="flex items-center justify-between p-4">
                    <h2 class="text-sm font-semibold">Wellness leave</h2>
                    <a href="{{ route('leave.index', ['employee' => $employee->id]) }}" class="text-xs text-primary-ink hover:underline">View all</a>
                </header>
                @if ($leaves->isEmpty())
                    <p class="px-4 pb-4 text-sm text-muted">No filings yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead><tr><th>Dates</th><th class="text-right">Days</th><th>Status</th></tr></thead>
                            <tbody>
                                @foreach ($leaves as $leave)
                                    <tr>
                                        <td class="whitespace-nowrap">
                                            <a href="{{ route('leave.edit', $leave) }}" class="hover:text-primary-ink">
                                                {{ HrDate::format($leave->inclusive_date_from) }}
                                                @unless ($leave->inclusive_date_from->eq($leave->inclusive_date_to))
                                                    <span class="text-muted">to {{ $leave->inclusive_date_to->format('M j') }}</span>
                                                @endunless
                                            </a>
                                        </td>
                                        <td class="text-right tabular">{{ $leave->days }}</td>
                                        <td><span class="{{ Badge::for($leave->status) }}">{{ $leave->status->value }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="card overflow-hidden">
                <header class="flex items-center justify-between p-4">
                    <h2 class="text-sm font-semibold">Certificates</h2>
                    <a href="{{ url("/certificates/new?employee={$employee->id}") }}" class="text-xs text-primary-ink hover:underline">Issue certificate</a>
                </header>
                @if ($certificates->isEmpty())
                    <p class="px-4 pb-4 text-sm text-muted">None issued yet.</p>
                @else
                    <ul class="divide-y divide-line border-t border-line">
                        @foreach ($certificates as $certificate)
                            <li>
                                <a href="{{ url("/certificates/{$certificate->id}") }}" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm transition-colors hover:bg-surface-2">
                                    <span class="min-w-0">
                                        <span class="block font-medium tabular">{{ $certificate->control_number }}</span>
                                        <span class="block truncate text-xs text-muted">{{ $certificate->template_name }} · {{ HrDate::format($certificate->issued_date) }}</span>
                                    </span>
                                    <span class="{{ $certificate->isVoid() ? 'badge-danger' : 'badge-success' }}">{{ $certificate->status->value }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="card overflow-hidden">
                <header class="p-4"><h2 class="text-sm font-semibold">Annual requirements</h2></header>
                @if ($requirementYears->isEmpty())
                    <p class="px-4 pb-4 text-sm text-muted">No records yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead><tr><th>Year</th><th>ITR</th><th>Sworn</th><th>PDS</th><th>IPCR</th></tr></thead>
                            <tbody>
                                @foreach ($requirementYears as $requirementYear)
                                    <tr>
                                        <td class="tabular">{{ $requirementYear }}</td>
                                        @foreach (['itr', 'sworn', 'pds'] as $key)
                                            @php($record = $requirements[$key]->firstWhere('year', $requirementYear))
                                            <td>
                                                @if ($record)
                                                    <span class="{{ Badge::TIMELINESS[$timely($record)['status']] }}" title="{{ $describe($record) }}">{{ $short[$timely($record)['status']] }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        @php($ratings = $requirements['ipcr']->where('year', $requirementYear))
                                        <td class="tabular">
                                            {{ $ratings->pluck('rating')->filter()->implode(', ') ?: '—' }}
                                            @if ($ratings->contains(fn ($rating) => in_array($timely($rating)['status'], ['late', 'overdue'], true)))
                                                <span class="badge-danger ml-1" title="{{ $ratings->map(fn ($rating) => $rating->rating_period->value.': '.$describe($rating))->implode("\n") }}">Late</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="card p-4">
                <h2 class="mb-3 text-sm font-semibold">Change history</h2>
                @if ($history->isEmpty())
                    <p class="text-sm text-muted">No changes recorded.</p>
                @else
                    <ol class="space-y-3">
                        @foreach ($history as $entry)
                            <li class="text-sm">
                                <p>
                                    <span class="font-medium">{{ $entry->user_name ?? 'System' }}</span>
                                    <span class="text-muted">{{ match ($entry->action) { 'create' => 'created this record', 'import' => 'imported this record', default => 'updated' } }}</span>
                                </p>
                                <p class="text-xs text-muted">{{ $entry->created_at->format('M j, Y g:i A') }}</p>
                                @if ($entry->action === 'update')
                                    <ul class="mt-1 space-y-0.5 text-xs text-fg-2">
                                        @foreach (array_slice($entry->changes ?? [], 0, 6) as $key => $change)
                                            <li>
                                                <span class="font-medium">{{ collect($fields)->firstWhere('name', $key)['label'] ?? $key }}</span>:
                                                {{ Str::limit((string) ($change['from'] ?? '—'), 40, '') }} → {{ Str::limit((string) ($change['to'] ?? '—'), 40, '') }}
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>
    </div>
</x-layouts.app>
