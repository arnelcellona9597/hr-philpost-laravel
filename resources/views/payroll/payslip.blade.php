@php
    use App\Support\Money;

    $symbol = $settings->currency_symbol ?: '₱';
    $period = $slip->period;
    $released = $period->isReleased();
    $quantity = fn ($value): string => (float) $value == 0.0 ? '' : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $peso = fn (int $centavos): string => $centavos === 0 ? '' : number_format($centavos / 100, 2, '.', '');
    $saved = [
        'batch' => (string) $slip->batch_label,
        'specific' => (string) $slip->specific_station,
        'covered' => (string) $slip->period_covered,
        'days' => $quantity($slip->days_worked),
        'late' => $slip->late_minutes ? (string) $slip->late_minutes : '',
        'ot' => $quantity($slip->ot_hours),
        'ot_rest' => $quantity($slip->ot_rest_hours),
        'sss' => $peso((int) $slip->sss_centavos),
        'hdmf' => $peso((int) $slip->hdmf_centavos),
        'cmpeai' => $peso((int) $slip->cmpeai_centavos),
        'absences' => $quantity($slip->absences),
        'tardiness' => $slip->tardiness ? (string) $slip->tardiness : '',
    ];
    $groups = [
        'Details' => ['batch', 'specific', 'covered'],
        'Attendance and overtime' => ['days', 'late', 'ot', 'ot_rest'],
        'Deductions (this period)' => ['sss', 'hdmf', 'cmpeai'],
        'Notes (do not change pay)' => ['absences', 'tardiness'],
    ];
    $computed = [
        ['Daily rate', $slip->daily_rate_centavos],
        ['Premium ('.rtrim(rtrim((string) $slip->premium_percent, '0'), '.').'%)', $pay['premium']],
        ['Total daily rate', $pay['total_daily_rate']],
        ['Gross', $pay['gross']],
        ['Total late', $pay['late']],
        ['Overtime pay', $pay['overtime']],
        ['Gross after late', $pay['gross_after_late']],
        ['Deductions', $pay['deductions']],
    ];
@endphp

<x-layouts.app :title="'Payslip — '.$slip->full_name" :heading="$slip->full_name" :description="$period->name.' · '.$slip->employee_code.' · '.$slip->station" :back="['href' => route('payroll.show', $period), 'label' => $period->name]">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('payroll.payslips.print', $slip) }}"><x-icon name="printer" /> Print</a>
        <a class="btn-secondary" href="{{ route('payroll.payslips.pdf', $slip) }}"><x-icon name="download" /> PDF</a>
        @if ($released)
            <form method="post" action="{{ route('payroll.payslips.resend', $slip) }}" data-confirm="Email this payslip to {{ $slip->employee->email ?: 'the worker' }}?">
                @csrf
                <button class="btn-primary"><x-icon name="send" /> {{ $slip->corrected_after_release ? 'Send corrected payslip' : 'Email again' }}</button>
            </form>
        @endif
    </x-slot:actions>

    @if ($slip->corrected_after_release)
        <div class="mb-4 rounded-lg bg-warning-soft px-4 py-3 text-sm text-warning">Changed after the payroll was released. Send the corrected payslip to the worker.</div>
    @endif

    @if ($errors->has('form'))
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first('form') }}</div>
    @endif

    <form method="post" action="{{ route('payroll.payslips.update', $slip) }}" class="grid gap-6 lg:grid-cols-2" data-dirty-guard>
        @csrf
        @method('PUT')
        @foreach ($groups as $title => $keys)
            <fieldset class="card space-y-3 p-5">
                <legend class="px-1 text-sm font-semibold">{{ $title }}</legend>
                @foreach ($keys as $key)
                    <div class="flex items-center gap-3">
                        <label class="flex-1 text-sm" for="{{ $key }}">{{ $inputs[$key][2] }}</label>
                        <input @class(['input max-w-44', 'text-right tabular' => ! in_array($inputs[$key][1], ['text', 'short'], true)]) id="{{ $key }}" name="{{ $key }}" value="{{ old($key, $saved[$key]) }}" />
                    </div>
                @endforeach
            </fieldset>
        @endforeach
        <div class="card space-y-4 p-5 lg:col-span-2">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                @foreach ($computed as [$label, $centavos])
                    <div><dt class="text-xs text-muted">{{ $label }}</dt><dd class="font-medium tabular">{{ Money::format((int) $centavos, $symbol) }}</dd></div>
                @endforeach
            </dl>
            <div class="flex items-center justify-between rounded-lg bg-primary-soft px-4 py-3">
                <span class="font-semibold">Net pay</span>
                <span class="text-xl font-semibold text-primary-ink tabular">{{ Money::format($slip->net_pay_centavos, $symbol) }}</span>
            </div>
            <div>
                <label class="label" for="remarks">Remarks</label>
                <textarea class="input" id="remarks" name="remarks">{{ old('remarks', $slip->remarks) }}</textarea>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <button class="btn-primary">Save payslip</button>
                <p class="text-xs text-muted">Computed amounts update after saving. Deductions changed here apply to this period only.</p>
            </div>
        </div>
    </form>

    @unless ($released)
        <form method="post" action="{{ route('payroll.payslips.destroy', $slip) }}" class="mt-6" data-confirm="Remove this worker from the payroll?">
            @csrf
            @method('DELETE')
            <button class="btn-danger btn-sm"><x-icon name="trash" class="size-3.5" /> Remove from payroll</button>
        </form>
    @endunless
</x-layouts.app>
