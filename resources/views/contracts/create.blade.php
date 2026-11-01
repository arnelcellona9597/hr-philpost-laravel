@php
    use App\Support\HrDate;
@endphp

<x-layouts.app title="Issue contracts" heading="Issue contracts of service" :back="['href' => route('contracts.index'), 'label' => 'Contracts']" description="Choose the template and the term, tick the workers, adjust anyone who differs, and issue them all together.">
    @if ($errors->has('form'))
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">
            <ul class="list-disc pl-5">
                @foreach (preg_split('/\n/', $errors->first('form')) as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($officialsMissing)
        <div class="mb-4 rounded-lg bg-warning-soft px-4 py-3 text-sm text-warning" role="status">
            ⚠ Some officials' names are not filled in, so their lines will print blank. <a class="underline" href="{{ route('contracts.settings') }}">Fill them in first</a>.
        </div>
    @endif

    <form method="get" action="{{ route('contracts.create') }}" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-56">
            <label class="label" for="template">Template</label>
            <select class="input" id="template" name="template">
                @foreach ($templates as $template)
                    <option value="{{ $template->id }}" @selected($template->id === $templateId)>{{ $template->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="term_start">Term starts</label>
            <input class="input" id="term_start" name="term_start" type="date" value="{{ $termStart }}" required />
        </div>
        <div>
            <label class="label" for="term_end">Term ends</label>
            <input class="input" id="term_end" name="term_end" type="date" value="{{ $termEnd }}" required />
        </div>
        <button class="btn-secondary"><x-icon name="search" /> Find workers</button>
        <p class="basis-full text-xs text-muted">Lists active COS workers who have no valid contract overlapping these dates.</p>
    </form>

    @if ($rows === [])
        <div class="card"><x-empty-state :title="$ready ? 'Nobody to list' : 'Choose the term first'" :text="$ready ? 'Everyone active on COS already has a contract in this term.' : 'Enter the start and end of the term and press “Find workers”.'" /></div>
    @else
        <form method="post" action="{{ route('contracts.store') }}" id="contract-grid" data-dirty-guard>
            @csrf
            <input type="hidden" name="grid" id="grid-input" />
            <input type="hidden" name="template" value="{{ $templateId }}" />
            <input type="hidden" name="term_start" value="{{ $termStart }}" />
            <input type="hidden" name="term_end" value="{{ $termEnd }}" />

            <section class="card mb-4 grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="label" for="issued_date">Date issued</label>
                    <input class="input" id="issued_date" name="issued_date" type="date" value="{{ $issuedDate }}" required />
                </div>
                <div>
                    <label class="label" for="premium_percent">Premium (%)</label>
                    <input class="input" id="premium_percent" name="premium_percent" type="number" min="0" max="100" value="{{ old('premium_percent', $settings->premium_percent) }}" />
                </div>
                <div>
                    <label class="label" for="second_wage">Second daily wage (optional)</label>
                    <input class="input text-right tabular" id="second_wage" name="second_wage" inputmode="decimal" value="{{ old('second_wage') }}" placeholder="from a later date" />
                </div>
                <div>
                    <label class="label" for="second_wage_from">Second wage starts</label>
                    <input class="input" id="second_wage_from" name="second_wage_from" type="date" value="{{ old('second_wage_from') }}" />
                </div>
                <div class="sm:col-span-2 lg:col-span-4">
                    <label class="label" for="wage_order">Wage order reference</label>
                    <input class="input" id="wage_order" name="wage_order" maxlength="255" value="{{ old('wage_order', $settings->wage_order) }}" />
                </div>
            </section>

            <section class="card overflow-hidden">
                <div class="max-h-[60vh] overflow-auto">
                    <table class="table text-xs [&_td]:align-middle" id="contract-rows">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th class="w-8"><input type="checkbox" id="select-all" class="size-4" checked aria-label="Select all" /></th>
                                <th class="min-w-44">Worker</th>
                                <th class="min-w-44">Position</th>
                                <th class="min-w-44">Office</th>
                                <th class="min-w-44">Place of service</th>
                                <th class="min-w-56">Address</th>
                                <th class="w-28 text-right">Daily wage</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr data-row data-employee="{{ $row['employee']->id }}">
                                    <td><input type="checkbox" data-selected class="size-4" @checked($row['checked']) aria-label="Issue for {{ $row['employee']->full_name }}" /></td>
                                    <td class="font-medium">{{ $row['employee']->full_name }}<span class="block font-mono text-[11px] text-muted">{{ $row['employee']->employee_id }}</span></td>
                                    @foreach (['position' => 'Position', 'office' => 'Office', 'place_of_service' => 'Place of service', 'address' => 'Address'] as $field => $label)
                                        <td class="!px-1"><input class="input py-1" data-field="{{ $field }}" value="{{ $row[$field] }}" aria-label="{{ $label }} for {{ $row['employee']->full_name }}" /></td>
                                    @endforeach
                                    <td class="!px-1"><input class="input py-1 text-right tabular" data-field="daily_wage" inputmode="decimal" value="{{ $row['daily_wage'] }}" aria-label="Daily wage for {{ $row['employee']->full_name }}" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line p-4">
                    <p class="text-xs text-muted"><span id="selected-count">{{ count(array_filter(array_column($rows, 'checked'))) }}</span> of {{ count($rows) }} selected. {{ $tooMany ? 'Only the first '.\App\Services\ContractService::MAX_BULK.' are listed; issue the rest in another round.' : 'All are issued together or none are.' }}</p>
                    <button class="btn-primary"><x-icon name="check" /> Issue contracts</button>
                </div>
            </section>
        </form>
    @endif
</x-layouts.app>
