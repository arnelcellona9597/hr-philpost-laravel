@php
    use App\Support\HrDate;
@endphp

<x-layouts.app title="Certificates" heading="Certificates of employment" description="Issue, print and track numbered certificates. Each certificate keeps the exact text it was issued with.">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('certificates.templates.index') }}"><x-icon name="settings" /> Templates</a>
        <a class="btn-primary" href="{{ route('certificates.create') }}"><x-icon name="plus" /> Issue certificate</a>
    </x-slot:actions>

    <form method="get" class="card mb-4 flex flex-wrap items-end gap-3 p-4" data-autosubmit>
        <div class="relative min-w-56 flex-1">
            <label for="q" class="label">Search</label>
            <input class="input" id="q" name="q" type="search" value="{{ $search }}" placeholder="Control number, employee or purpose" />
        </div>
        <div>
            <label for="template" class="label">Certificate</label>
            <select class="input" id="template" name="template">
                <option value="">All certificates</option>
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
        <button class="btn-secondary"><x-icon name="search" /> Search</button>
        @if ($filtered)
            <a class="btn-ghost" href="{{ route('certificates.index') }}">Clear</a>
        @endif
    </form>

    <div class="card overflow-hidden">
        @if ($certificates->isEmpty())
            <x-empty-state :title="$filtered ? 'No certificates match.' : 'No certificates issued yet.'" :text="$filtered ? 'Try another search.' : 'Issue the first one; it gets a control number like COE-2026-0001.'">
                <a class="btn-primary" href="{{ route('certificates.create') }}"><x-icon name="plus" /> Issue certificate</a>
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Control No.</th>
                            <th>Employee</th>
                            <th>Certificate</th>
                            <th class="hidden lg:table-cell">Purpose</th>
                            <th>Issued</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($certificates as $certificate)
                            <tr>
                                <td class="whitespace-nowrap font-medium tabular">
                                    <a href="{{ route('certificates.show', $certificate) }}" class="text-primary-ink hover:underline">{{ $certificate->control_number }}</a>
                                </td>
                                <td>{{ $certificate->employee_name }}</td>
                                <td class="text-fg-2">{{ $certificate->template_name }}</td>
                                <td class="hidden max-w-xs truncate text-fg-2 lg:table-cell" title="{{ $certificate->purpose }}">{{ $certificate->purpose ?: '—' }}</td>
                                <td class="whitespace-nowrap text-fg-2">
                                    {{ HrDate::format($certificate->issued_date) }}
                                    <span class="block text-xs text-muted">{{ $certificate->issued_by_name }}</span>
                                </td>
                                <td><span class="{{ $certificate->isVoid() ? 'badge-danger' : 'badge-success' }}">{{ $certificate->status->value }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$certificates" />
        @endif
    </div>
</x-layouts.app>
