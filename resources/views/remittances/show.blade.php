@php
    use App\Enums\RemittanceKind;
    use App\Support\HrDate;
    use App\Support\Money;

    $kind = $remittance->kind;
    $final = $remittance->isFinalized();
    $headings = [
        ['Last name', 'min-w-36'], ['First name', 'min-w-32'], ['Middle name', 'min-w-24'], ['Ext.', 'w-16'],
        ...($kind === RemittanceKind::Sss ? [['Date of birth', 'w-32']] : []),
        $kind === RemittanceKind::Cmpeai ? ['Station', 'min-w-40'] : [$kind->memberLabel(), 'min-w-36'],
        ...($kind === RemittanceKind::Hdmf ? [['Monthly compensation', 'w-28 text-right'], ['Employer share', 'w-24 text-right']] : []),
        [$kind->amountLabel(), 'w-28 text-right'],
        ['Remarks', 'min-w-32'],
    ];
@endphp

<x-layouts.app :title="$remittance->label" :heading="$remittance->label" :back="['href' => route('remittances.index', ['kind' => $kind->value]), 'label' => 'Remittances']">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('remittances.export', $remittance) }}"><x-icon name="download" /> Excel</a>
        <a class="btn-secondary" href="{{ route('remittances.print', $remittance) }}" target="_blank" rel="noopener"><x-icon name="printer" /> Print list</a>
        <a class="btn-secondary" href="{{ route('remittances.voucher.pdf', $remittance) }}" target="_blank" rel="noopener"><x-icon name="file" /> Voucher (PDF)</a>
        <a class="btn-ghost" href="{{ route('remittances.voucher', $remittance) }}" target="_blank" rel="noopener">Print voucher</a>
    </x-slot:actions>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="People" :value="number_format($totals['count'])" />
        @if ($kind === RemittanceKind::Cmpeai)
            <x-stat-card label="Gross" :value="Money::format($totals['gross'])" />
            <x-stat-card :label="'Service charge ('.rtrim(rtrim(number_format($setting->service_charge_basis_points / 100, 2), '0'), '.').'%)'" :value="Money::format($totals['serviceCharge'])" />
        @elseif ($kind === RemittanceKind::Hdmf)
            <x-stat-card label="Employee share" :value="Money::format($totals['gross'])" />
            <x-stat-card label="Employer share" :value="Money::format($totals['employer'])" />
        @else
            <x-stat-card label="Contributions" :value="Money::format($totals['gross'])" />
            <x-stat-card label="Status" :value="$remittance->status->value" />
        @endif
        <x-stat-card label="Amount to pay" :value="Money::format($totals['payable'])" />
    </div>

    @if ($errors->has('form'))
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">
            <p class="font-medium">Nothing was saved. Fix these and save again:</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach (preg_split('/\n/', $errors->first('form')) as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($problems !== [])
        <div class="mb-4 rounded-lg bg-warning-soft px-4 py-3 text-sm text-warning" role="status">
            @foreach ($problems as $problem)
                <p>⚠ {{ $problem }}</p>
            @endforeach
        </div>
    @endif

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <span class="{{ $final ? 'badge-success' : 'badge-warning' }}">{{ $remittance->status->value }}</span>
        @if ($final)
            <form method="post" action="{{ route('remittances.reopen', $remittance) }}" data-confirm="Reopen {{ $remittance->label }} for changes?">
                @csrf
                <button class="btn-secondary btn-sm">Reopen</button>
            </form>
        @else
            @if ($missing > 0)
                <form method="post" action="{{ route('remittances.missing', $remittance) }}">
                    @csrf
                    <button class="btn-secondary btn-sm"><x-icon name="plus" class="size-3.5" /> Add {{ $missing }} active COS worker{{ $missing === 1 ? '' : 's' }} not listed</button>
                </form>
            @endif
            <form method="post" action="{{ route('remittances.finalize', $remittance) }}" data-confirm="Finalize {{ $remittance->label }}? The list is locked until you reopen it.">
                @csrf
                <button class="btn-primary btn-sm"><x-icon name="check" class="size-3.5" /> Finalize</button>
            </form>
        @endif
    </div>

    <form method="post" action="{{ route('remittances.update', $remittance) }}" id="remittance-grid" class="card overflow-hidden" data-dirty-guard data-cmpeai-bp="{{ $kind === RemittanceKind::Cmpeai ? $setting->service_charge_basis_points : 0 }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="grid" id="grid-input" />
        <div class="max-h-[65vh] overflow-auto">
            <table class="table text-xs [&_td]:align-middle" id="remittance-rows">
                <thead class="sticky top-0 z-10">
                    <tr>
                        <th class="w-10 text-right">#</th>
                        @foreach ($headings as [$label, $class])
                            <th class="{{ $class }} !normal-case">{{ $label }}</th>
                        @endforeach
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        @include('remittances._row', ['line' => $line, 'kind' => $kind, 'final' => $final, 'number' => $loop->iteration])
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td colspan="{{ count($headings) - ($kind === RemittanceKind::Hdmf ? 4 : 2) + 1 }}" class="text-right">Total</td>
                        @if ($kind === RemittanceKind::Hdmf)
                            <td class="text-right tabular" data-total="compensation">{{ Money::format($totals['compensation']) }}</td>
                            <td class="text-right tabular" data-total="employer_share">{{ Money::format($totals['employer']) }}</td>
                        @endif
                        <td class="text-right tabular" data-total="amount">{{ Money::format($totals['gross']) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @unless ($final)
            <template id="row-template">
                @include('remittances._row', ['line' => null, 'kind' => $kind, 'final' => false, 'number' => ''])
            </template>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line p-4">
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" class="btn-secondary btn-sm" id="add-person"><x-icon name="plus" class="size-3.5" /> Add person</button>
                    <p class="text-xs text-muted">Amounts in pesos, up to 2 decimals. Every row is saved together or not at all.</p>
                </div>
                <button class="btn-primary">Save list</button>
            </div>
        @endunless
    </form>

    <section class="card mt-6 p-5">
        <h2 class="text-sm font-semibold">Disbursement voucher</h2>
        <form method="post" action="{{ route('remittances.voucher-details', $remittance) }}" class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @csrf
            @method('PUT')
            <div>
                <label class="label" for="voucher_number">Voucher number</label>
                <input class="input" id="voucher_number" name="voucher_number" maxlength="40" value="{{ old('voucher_number', $remittance->voucher_number) }}" />
            </div>
            <div>
                <label class="label" for="voucher_date">Voucher date</label>
                <input class="input" id="voucher_date" name="voucher_date" type="date" value="{{ old('voucher_date', $remittance->voucher_date?->toDateString()) }}" />
                @error('voucher_date')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="mode_of_payment">Mode of payment</label>
                <input class="input" id="mode_of_payment" name="mode_of_payment" maxlength="40" placeholder="{{ $voucher->mode_of_payment }}" value="{{ old('mode_of_payment', $remittance->mode_of_payment) }}" />
            </div>
            <div class="sm:col-span-2 lg:col-span-4">
                <label class="label" for="notes">Notes</label>
                <textarea class="input" id="notes" name="notes" rows="2" maxlength="2000">{{ old('notes', $remittance->notes) }}</textarea>
            </div>
            <div class="sm:col-span-2 lg:col-span-4"><button class="btn-secondary btn-sm">Save voucher details</button></div>
        </form>
    </section>

    @unless ($final)
        <form method="post" action="{{ route('remittances.destroy', $remittance) }}" class="mt-6" data-confirm="Delete {{ $remittance->label }} and its list of people? This cannot be undone.">
            @csrf
            @method('DELETE')
            <button class="btn-danger btn-sm"><x-icon name="trash" class="size-3.5" /> Delete this month</button>
        </form>
    @endunless
</x-layouts.app>
