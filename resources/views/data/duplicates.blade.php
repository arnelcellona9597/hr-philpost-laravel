@php
    use App\Support\HrDate;
@endphp

<x-layouts.app title="Duplicates" heading="Possible duplicates" :back="['href' => route('data.index'), 'label' => 'Import / Export']" description="Records that look like the same person entered more than once. The oldest copy in each group is kept by default; tick the copies to delete.">
    <nav class="mb-4 flex flex-wrap gap-2" aria-label="Records">
        @foreach ($modules as $key => $label)
            <a @class(['btn-sm', 'btn-primary' => $key === $module, 'btn-secondary' => $key !== $module]) href="{{ route('data.duplicates', ['module' => $key]) }}">{{ $label }}</a>
        @endforeach
    </nav>

    @if ($groups === [])
        <div class="card"><x-empty-state title="No duplicates found" :text="$module === 'employees' ? 'No two employees share a name and birth date, an email, or a TIN, SSS, PhilHealth or Pag-IBIG number.' : 'No two applicants share a name, position and date applied.'" /></div>
    @else
        <p class="mb-3 text-sm text-muted">{{ count($groups) }} group{{ count($groups) === 1 ? '' : 's' }} to review. Deleting cannot be undone. Employees who already have leave, payslips or other records are kept.</p>
        <div class="space-y-4">
            @foreach ($groups as $group)
                <form method="post" action="{{ route('data.duplicates.destroy') }}" class="card overflow-hidden" data-confirm="Delete the ticked records? This cannot be undone.">
                    @csrf
                    <input type="hidden" name="module" value="{{ $module }}" />
                    <header class="flex flex-wrap items-center justify-between gap-2 p-4">
                        <h2 class="text-sm font-semibold">{{ $group['reason'] }} <span class="font-normal text-muted">· {{ count($group['ids']) }} records</span></h2>
                        <button class="btn-danger btn-sm"><x-icon name="trash" class="size-3.5" /> Delete ticked</button>
                    </header>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead><tr><th class="w-8">Delete</th><th>Record</th><th>Details</th><th>Added</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($group['ids'] as $index => $id)
                                    @php($record = $records[$id])
                                    <tr>
                                        <td><input type="checkbox" name="ids[]" value="{{ $id }}" class="size-4" @checked($index > 0) aria-label="Delete this copy" /></td>
                                        @if ($module === 'employees')
                                            <td class="font-medium">{{ $record->full_name }}<span class="block font-mono text-[11px] text-muted">{{ $record->employee_id }}</span></td>
                                            <td class="text-fg-2">{{ $record->position ?: '—' }} · {{ $record->station?->name ?: '—' }}<span class="block text-xs text-muted">{{ $record->email ?: 'no email' }} · born {{ HrDate::format($record->date_of_birth) }}</span></td>
                                        @else
                                            <td class="font-medium">{{ $record->applicant_name }}<span class="block font-mono text-[11px] text-muted">{{ $record->application_id }}</span></td>
                                            <td class="text-fg-2">{{ $record->position_applied }} · {{ $record->station?->name ?: '—' }}<span class="block text-xs text-muted">applied {{ HrDate::format($record->date_applied) }} · {{ $record->application_status->value }}</span></td>
                                        @endif
                                        <td class="whitespace-nowrap text-fg-2 tabular">{{ HrDate::format($record->created_at) }}@if ($index === 0)<span class="block text-xs text-success">oldest, kept</span>@endif</td>
                                        <td class="text-right"><a class="btn-ghost btn-sm" href="{{ $module === 'employees' ? route('employees.show', $record) : route('onboarding.show', $record) }}" target="_blank" rel="noopener">Open</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </form>
            @endforeach
        </div>
    @endif
</x-layouts.app>
