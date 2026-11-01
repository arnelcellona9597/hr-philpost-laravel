@php
    use App\Support\HrDate;
@endphp

<x-layouts.app title="Contracts" heading="Contracts of service" description="Issue, print and track numbered contracts for contract of service workers. Each contract keeps the exact text it was issued with.">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('contracts.settings') }}"><x-icon name="settings" /> Officials and wages</a>
        <a class="btn-secondary" href="{{ route('contracts.templates.index') }}"><x-icon name="edit" /> Templates</a>
        <a class="btn-primary" href="{{ route('contracts.create') }}"><x-icon name="plus" /> Issue contracts</a>
    </x-slot:actions>

    @if ($endingCount > 0 && ! $ending)
        <a class="mb-4 flex items-center gap-2 rounded-lg bg-warning-soft px-4 py-3 text-sm text-warning hover:underline" href="{{ route('contracts.index', ['ending' => 1]) }}">
            <x-icon name="clock" class="size-4" /> {{ number_format($endingCount) }} contract{{ $endingCount === 1 ? ' ends' : 's end' }} within 30 days. Show them.
        </a>
    @endif

    @if ($errors->has('form'))
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first('form') }}</div>
    @endif

    <form method="get" class="card mb-4 flex flex-wrap items-end gap-3 p-4" data-autosubmit>
        <div class="min-w-56 flex-1">
            <label for="q" class="label">Search</label>
            <input class="input" id="q" name="q" type="search" value="{{ $search }}" placeholder="Number, name, position or office" />
        </div>
        <div>
            <label for="template" class="label">Template</label>
            <select class="input" id="template" name="template">
                <option value="">All templates</option>
                @foreach ($templates as $template)
                    <option value="{{ $template->id }}" @selected($template->id === $templateId)>{{ $template->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status" class="label">Status</label>
            <select class="input" id="status" name="status">
                <option value="">Any</option>
                @foreach (['Valid', 'Void'] as $option)
                    <option @selected($option === $status?->value)>{{ $option }}</option>
                @endforeach
            </select>
        </div>
        <label class="mb-2 flex items-center gap-2 text-sm"><input type="checkbox" name="ending" value="1" @checked($ending) class="size-4" /> Ending within 30 days</label>
        <button class="btn-secondary"><x-icon name="search" /> Search</button>
        @if ($filtered)
            <a class="btn-ghost" href="{{ route('contracts.index') }}">Clear</a>
        @endif
    </form>

    <form method="post" action="{{ route('contracts.download') }}" class="card overflow-hidden">
        @csrf
        @if ($contracts->isEmpty())
            <x-empty-state :title="$filtered ? 'No contracts match.' : 'No contracts issued yet.'" :text="$filtered ? 'Try another search.' : 'Issue the first ones; each gets a number like COS-2026-0001.'">
                <a class="btn-primary" href="{{ route('contracts.create') }}"><x-icon name="plus" /> Issue contracts</a>
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="w-8"></th>
                            <th>Contract No.</th>
                            <th>Worker</th>
                            <th class="hidden lg:table-cell">Position</th>
                            <th>Term</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($contracts as $contract)
                            <tr>
                                <td><input type="checkbox" name="ids[]" value="{{ $contract->id }}" class="size-4" aria-label="Select {{ $contract->control_number }}" /></td>
                                <td class="whitespace-nowrap font-medium tabular"><a href="{{ route('contracts.show', $contract) }}" class="text-primary-ink hover:underline">{{ $contract->control_number }}</a></td>
                                <td>{{ $contract->employee_name }}<span class="block text-xs text-muted">{{ $contract->office }}</span></td>
                                <td class="hidden text-fg-2 lg:table-cell">{{ $contract->position }}</td>
                                <td class="whitespace-nowrap text-fg-2 tabular">{{ HrDate::format($contract->term_start) }} – {{ HrDate::format($contract->term_end) }}</td>
                                <td>
                                    <span class="{{ $contract->isVoid() ? 'badge-danger' : 'badge-success' }}">{{ $contract->status->value }}</span>
                                    @if (! $contract->isVoid() && $contract->term_end->toDateString() >= $today && $contract->term_end->toDateString() <= $soon)
                                        <span class="badge-warning">ends soon</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-center gap-3 border-t border-line p-4">
                <span class="text-xs text-muted">Tick up to {{ $maxZip }} contracts, then download them as one ZIP.</span>
                <button class="btn-secondary btn-sm" name="format" value="pdf"><x-icon name="download" class="size-3.5" /> PDFs (ZIP)</button>
                <button class="btn-secondary btn-sm" name="format" value="docx"><x-icon name="download" class="size-3.5" /> Word files (ZIP)</button>
            </div>
            <x-pagination :paginator="$contracts" />
        @endif
    </form>
</x-layouts.app>
