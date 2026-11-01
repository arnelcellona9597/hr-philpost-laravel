@php
    use App\Support\Money;

    $symbol = $settings->currency_symbol ?: '₱';
    $period = $slip->period;
    $released = $period->isReleased();
    $amount = fn (string $label, array $items): string => number_format((array_column($items, 'amount_centavos', 'label')[$label] ?? 0) / 100, 2, '.', '');
    $sections = [['Earnings', $labels['earnings'], 'e', $slip->earnings], ['Deductions', $labels['deductions'], 'd', $slip->deductions]];
@endphp

<x-layouts.app :title="'Payslip — '.$slip->full_name" :heading="$slip->full_name" :description="$period->name.' · '.$slip->employee_code.' · '.$slip->position" :back="['href' => route('payroll.show', $period), 'label' => $period->name]">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('payroll.payslips.print', $slip) }}"><x-icon name="printer" /> Print</a>
        <a class="btn-secondary" href="{{ route('payroll.payslips.pdf', $slip) }}"><x-icon name="download" /> PDF</a>
        @if ($released)
            <form method="post" action="{{ route('payroll.payslips.resend', $slip) }}" data-confirm="Email this payslip to {{ $slip->employee->email ?: 'the employee' }}?">
                @csrf
                <button class="btn-primary"><x-icon name="send" /> {{ $slip->corrected_after_release ? 'Send corrected payslip' : 'Email again' }}</button>
            </form>
        @endif
    </x-slot:actions>

    @if ($slip->corrected_after_release)
        <div class="mb-4 rounded-lg bg-warning-soft px-4 py-3 text-sm text-warning">Changed after the payroll was released. Send the corrected payslip to the employee.</div>
    @endif

    @if ($errors->has('form'))
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first('form') }}</div>
    @endif

    <form method="post" action="{{ route('payroll.payslips.update', $slip) }}" class="grid gap-6 lg:grid-cols-2" data-dirty-guard>
        @csrf
        @method('PUT')
        @foreach ($sections as [$title, $list, $kind, $items])
            <fieldset class="card space-y-3 p-5">
                <legend class="px-1 text-sm font-semibold">{{ $title }}</legend>
                @foreach ($list as $index => $label)
                    <div class="flex items-center gap-3">
                        <label class="flex-1 text-sm" for="{{ $kind }}_{{ $index }}">{{ $label }}</label>
                        <input class="input max-w-40 text-right tabular" id="{{ $kind }}_{{ $index }}" name="{{ $kind }}_{{ $index }}" inputmode="decimal" value="{{ old("{$kind}_{$index}", $amount($label, $items)) }}" />
                    </div>
                @endforeach
            </fieldset>
        @endforeach
        @if ($extra !== [])
            <p class="text-xs text-muted lg:col-span-2">
                Also on this payslip (not in the template):
                {{ collect($extra)->map(fn (array $item): string => $item['label'].' '.Money::format($item['amount_centavos'], $symbol))->implode(', ') }}.
            </p>
        @endif
        <div class="card space-y-3 p-5 lg:col-span-2">
            <dl class="grid grid-cols-3 gap-4 text-center">
                <div><dt class="text-xs text-muted">Gross pay</dt><dd class="text-xl font-semibold tabular">{{ Money::format($slip->gross_pay_centavos, $symbol) }}</dd></div>
                <div><dt class="text-xs text-muted">Deductions</dt><dd class="text-xl font-semibold tabular">{{ Money::format($slip->total_deductions_centavos, $symbol) }}</dd></div>
                <div><dt class="text-xs text-muted">Net pay</dt><dd class="text-xl font-semibold text-primary-ink tabular">{{ Money::format($slip->net_pay_centavos, $symbol) }}</dd></div>
            </dl>
            <div>
                <label class="label" for="remarks">Remarks</label>
                <textarea class="input" id="remarks" name="remarks">{{ old('remarks', $slip->remarks) }}</textarea>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <button class="btn-primary">Save payslip</button>
                <p class="text-xs text-muted">Totals update after saving.</p>
            </div>
        </div>
    </form>

    @unless ($released)
        <form method="post" action="{{ route('payroll.payslips.destroy', $slip) }}" class="mt-6" data-confirm="Delete this payslip?">
            @csrf
            @method('DELETE')
            <button class="btn-danger btn-sm"><x-icon name="trash" class="size-3.5" /> Delete payslip</button>
        </form>
    @endunless
</x-layouts.app>
