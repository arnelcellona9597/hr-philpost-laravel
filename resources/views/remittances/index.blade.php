@php
    use App\Support\Money;
    use Illuminate\Support\Carbon;

    $monthName = fn (int $number): string => Carbon::create(2000, $number, 1)->format('F');
@endphp

<x-layouts.app title="Remittances" heading="Remittances" description="Monthly SSS, Pag-IBIG and CMPEAI contributions for contract of service workers, with the disbursement voucher for each.">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('remittances.settings', ['kind' => $kind->value]) }}"><x-icon name="settings" /> Templates</a>
    </x-slot:actions>

    <nav class="mb-4 flex flex-wrap gap-2" aria-label="Fund">
        @foreach ($kinds as $option)
            <a @class(['btn-sm', 'btn-primary' => $option === $kind, 'btn-secondary' => $option !== $kind]) href="{{ route('remittances.index', ['kind' => $option->value]) }}" @if ($option === $kind) aria-current="page" @endif>{{ $option->label() }}</a>
        @endforeach
    </nav>

    <section class="card mb-4 p-4">
        <h2 class="text-sm font-semibold">Start a month</h2>
        <p class="mt-1 text-xs text-muted">{{ $hasEarlier ? 'The list starts from your latest earlier month, without anyone who has left.' : 'The first month lists active COS workers who have a '.$kind->memberLabel().' on file.' }}</p>
        <form method="post" action="{{ route('remittances.store') }}" class="mt-3 flex flex-wrap items-end gap-3">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind->value }}" />
            <div>
                <label class="label" for="month">Month</label>
                <select class="input" id="month" name="month">
                    @foreach (range(1, 12) as $number)
                        <option value="{{ $number }}" @selected($number === $nextMonth)>{{ $monthName($number) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="year">Year</label>
                <input class="input w-28" id="year" name="year" type="number" min="2000" max="2100" value="{{ $nextYear }}" />
            </div>
            <button class="btn-primary"><x-icon name="plus" /> Start month</button>
        </form>
    </section>

    <div class="card overflow-hidden">
        @if ($remittances->isEmpty())
            <x-empty-state title="No months yet" text="Start a month above, or import the Excel sheets you keep now." />
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Month</th><th class="text-right">People</th><th class="text-right">Amount to pay</th><th>Voucher</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($remittances as $remittance)
                            <tr>
                                <td class="font-medium"><a class="hover:text-primary-ink" href="{{ route('remittances.show', $remittance) }}">{{ $remittance->periodLabel() }}</a></td>
                                <td class="text-right tabular">{{ number_format($remittance->lines_count) }}</td>
                                <td class="text-right tabular">{{ Money::format($totals[$remittance->id]['payable']) }}</td>
                                <td class="text-fg-2">{{ $remittance->voucher_number ?: '—' }}</td>
                                <td><span class="{{ $remittance->isFinalized() ? 'badge-success' : 'badge-warning' }}">{{ $remittance->status->value }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$remittances" />
        @endif
    </div>

    <details class="card mt-6 p-5">
        <summary class="cursor-pointer text-sm font-semibold">Import your {{ $kind->label() }} Excel sheets</summary>
        <p class="mt-2 text-sm text-muted">Upload the workbook you keep now. Each sheet is one month, named like “OCTOBER 2026”. People are matched to employees by {{ $kind->memberLabel() }}, then by name. A month that is already here is replaced unless it is finalized. You see the result before anything is saved.</p>
        <form method="post" action="{{ route('remittances.import') }}" enctype="multipart/form-data" class="mt-3 flex flex-wrap items-end gap-3">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind->value }}" />
            <div>
                <label class="label" for="file">Excel file (.xlsx)</label>
                <input class="input" id="file" name="file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
            </div>
            <div>
                <label class="label" for="import_month">If a sheet has no month in its name</label>
                <div class="flex gap-2">
                    <select class="input" id="import_month" name="month">
                        <option value="">—</option>
                        @foreach (range(1, 12) as $number)
                            <option value="{{ $number }}">{{ $monthName($number) }}</option>
                        @endforeach
                    </select>
                    <input class="input w-24" name="year" type="number" min="2000" max="2100" value="{{ $nextYear }}" aria-label="Year" />
                </div>
            </div>
            <button class="btn-secondary"><x-icon name="upload" /> Check file</button>
        </form>
    </details>
</x-layouts.app>
