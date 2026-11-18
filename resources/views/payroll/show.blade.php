@php
    use App\Support\HrDate;
    use App\Support\Money;

    $symbol = $settings->currency_symbol ?: '₱';
    $released = $period->isReleased();
    $corrected = $slips->where('corrected_after_release', true)->count();
    $withoutRate = $slips->where('daily_rate_centavos', 0)->count();
    $posted = collect(json_decode((string) old('grid'), true) ?: [])->pluck('values', 'id');
    $quantity = fn ($value): string => (float) $value == 0.0 ? '' : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $peso = fn (int $centavos): string => $centavos === 0 ? '' : number_format($centavos / 100, 2, '.', '');
    $amount = fn (int $centavos): string => number_format($centavos / 100, 2);
    // What each editable cell shows: the value typed before a failed save, else the saved value.
    $value = function ($slip, string $key) use ($posted, $quantity, $peso): string {
        if (isset($posted[$slip->id][$key])) {
            return (string) $posted[$slip->id][$key];
        }

        return match ($key) {
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
        };
    };
    $input = fn ($slip, string $key, string $label, string $width = 'w-16', string $mode = 'decimal'): string => '<input class="input '.$width.' px-1.5 py-1 text-right tabular" inputmode="'.$mode.'" data-in="'.$key.'" value="'.e($value($slip, $key)).'" aria-label="'.e($label.' for '.$slip->full_name).'" />';
    $text = fn ($slip, string $key, string $label, string $width): string => '<input class="input '.$width.' px-1.5 py-1" data-in="'.$key.'" value="'.e($value($slip, $key)).'" aria-label="'.e($label.' for '.$slip->full_name).'" />';
@endphp

<x-layouts.app :title="$period->name" :heading="$period->name" :back="['href' => route('payroll.index'), 'label' => 'Payroll']" :description="'COS payroll · '.$period->titleRange().($period->batch ? ' · Batch '.$period->batch : '').' · Pay date '.HrDate::format($period->pay_date).' · '.$period->code">
    <x-slot:actions>
        <span class="{{ $released ? 'badge-success' : 'badge-warning' }}">{{ $period->status->value }}</span>
        <a class="btn-secondary" href="{{ route('payroll.edit', $period) }}"><x-icon name="edit" /> Edit period</a>
        @if ($slips->isNotEmpty())
            <a class="btn-secondary" href="{{ route('payroll.register', $period) }}"><x-icon name="printer" /> Payroll sheet</a>
            <a class="btn-secondary" href="{{ route('payroll.register.pdf', $period) }}"><x-icon name="download" /> Sheet PDF</a>
            <a class="btn-secondary" href="{{ route('payroll.export', $period) }}"><x-icon name="download" /> Excel</a>
            <a class="btn-secondary" href="{{ route('payroll.print', $period) }}"><x-icon name="printer" /> Payslips</a>
        @endif
    </x-slot:actions>

    @if ($released)
        <div class="mb-4 rounded-lg bg-success-soft px-4 py-3 text-sm text-success">
            Released {{ $period->released_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}. Payslips you change now are marked “corrected” so you can re-send them.
            @if ($corrected > 0)
                <strong>{{ $corrected }} payslip(s) were corrected after release.</strong>
            @endif
        </div>
    @endif

    @if ($withoutRate > 0)
        <div class="mb-4 rounded-lg bg-warning-soft px-4 py-3 text-sm text-warning">
            {{ $withoutRate }} worker(s) have no daily rate. Add it in their pay history (Employees → profile → Pay history), then use <strong>Refresh rates</strong>.
        </div>
    @endif

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4"><p class="text-xs text-muted">COS workers</p><p class="mt-1 text-2xl font-semibold tabular">{{ $slips->count() }}</p></div>
        <div class="card p-4"><p class="text-xs text-muted">Total gross after late</p><p class="mt-1 text-2xl font-semibold tabular">{{ Money::format($slips->sum('gross_pay_centavos') - $slips->sum(fn ($slip) => $slip->cosPay()['late']), $symbol) }}</p></div>
        <div class="card p-4"><p class="text-xs text-muted">Total net pay</p><p class="mt-1 text-2xl font-semibold tabular">{{ Money::format($slips->sum('net_pay_centavos'), $symbol) }}</p></div>
        <div class="card p-4"><p class="text-xs text-muted">No days entered</p><p class="mt-1 text-2xl font-semibold tabular">{{ $slips->where('gross_pay_centavos', 0)->count() }}</p></div>
    </div>

    @unless ($released)
        <div class="mb-6 grid gap-4 lg:grid-cols-2">
            <form method="post" action="{{ route('payroll.generate', $period) }}" class="card space-y-3 p-4">
                @csrf
                <h2 class="text-sm font-semibold">Add COS workers</h2>
                <p class="text-xs text-muted">Adds every active COS worker who isn't in this payroll yet, with their daily rate and premium from pay history and their usual SSS / HDMF / CMPEAI. Pick branches to add only those; leave empty for all.</p>
                <select class="input" name="branch[]" multiple size="3" aria-label="Branches">
                    @foreach ($branches as $branch)
                        <option value="{{ $branch['value'] }}">{{ $branch['label'] }}</option>
                    @endforeach
                </select>
                <button class="btn-secondary btn-sm"><x-icon name="plus" class="size-3.5" /> Add workers</button>
            </form>
            <div class="card space-y-3 p-4">
                <h2 class="text-sm font-semibold">Rates and release</h2>
                <form method="post" action="{{ route('payroll.refresh-rates', $period) }}" class="flex flex-wrap items-center gap-3">
                    @csrf
                    <p class="flex-1 text-xs text-muted">After a wage order or a pay-history fix, read every worker's daily rate and premium again.</p>
                    <button class="btn-secondary btn-sm" @disabled($slips->isEmpty())><x-icon name="history" class="size-3.5" /> Refresh rates</button>
                </form>
                @if ($slips->isNotEmpty())
                    <form method="post" action="{{ route('payroll.release', $period) }}" data-confirm="Release {{ $period->name }}? {{ $slips->count() }} payslip(s) will be final and emailed to the workers." class="flex flex-wrap items-center gap-3 border-t border-line pt-3">
                        @csrf
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="notify" value="1" checked class="size-4" /> Email each worker their payslip (PDF)</label>
                        <button class="btn-primary btn-sm"><x-icon name="send" class="size-3.5" /> Release payroll</button>
                    </form>
                @endif
            </div>
        </div>
    @endunless

    @if ($errors->has('grid') || $errors->has('form'))
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">
            <p class="font-medium">Nothing was saved. Fix these and save again:</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach (array_slice([...$errors->get('grid'), ...$errors->get('form')], 0, 20) as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card overflow-hidden">
        @if ($slips->isEmpty())
            <x-empty-state title="No workers in this payroll yet" text="Use “Add COS workers” above." />
        @else
            <form method="post" action="{{ route('payroll.grid', $period) }}" id="payroll-grid" data-dirty-guard>
                @csrf
                @method('PUT')
                <input type="hidden" name="grid" id="grid-input" />
                <div class="max-h-[72vh] overflow-auto">
                    <table class="table text-xs [&_td]:align-middle [&_td]:whitespace-nowrap [&_th]:whitespace-normal">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th class="sticky left-0 z-20 min-w-44">Name</th>
                                <th>Official station</th>
                                <th>Specific station</th>
                                <th>Batch</th>
                                <th class="text-right">Daily rate</th>
                                <th class="text-right">Premium</th>
                                <th class="text-right">Total daily rate</th>
                                <th>Date period covered</th>
                                <th class="text-right">No. of days</th>
                                <th class="text-right">Gross</th>
                                <th class="text-right">Late (mins)</th>
                                <th class="text-right">Rate/min</th>
                                <th class="text-right">Total late</th>
                                <th class="text-right">OT hours</th>
                                <th class="text-right">Hourly rate</th>
                                <th class="text-right">OT hours RD/SH</th>
                                <th class="text-right">Hourly RD/SH</th>
                                <th class="text-right">Overtime pay</th>
                                <th class="text-right">Gross after late</th>
                                <th class="text-right">SSS</th>
                                <th class="text-right">HDMF</th>
                                <th class="text-right">CMPEAI</th>
                                <th class="text-right">Net pay</th>
                                <th class="text-right">Absences</th>
                                <th class="text-right">Tardiness</th>
                                <th class="text-right">Excess</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($slips as $slip)
                                @php($pay = $slip->cosPay())
                                <tr data-row="{{ $slip->id }}" data-rate="{{ (int) $slip->daily_rate_centavos }}" data-premium="{{ (string) $slip->premium_percent }}">
                                    <td class="sticky left-0 z-[1] bg-surface">
                                        <a class="font-medium hover:text-primary-ink" href="{{ route('payroll.payslips.show', $slip) }}">{{ $slip->full_name }}</a>
                                        <span class="block font-mono text-[11px] text-muted">{{ $slip->employee_code }}</span>
                                        @if ($slip->corrected_after_release)
                                            <span class="badge-danger">corrected</span>
                                        @endif
                                    </td>
                                    <td>{{ $slip->station }}</td>
                                    <td class="!px-1">{!! $text($slip, 'specific', 'Specific station', 'w-28') !!}</td>
                                    <td class="!px-1">{!! $text($slip, 'batch', 'Batch', 'w-12') !!}</td>
                                    <td @class(['text-right tabular', 'text-danger' => ! $slip->daily_rate_centavos])>{{ $amount((int) $slip->daily_rate_centavos) }}</td>
                                    <td class="text-right tabular" data-out="premium">{{ $amount($pay['premium']) }}</td>
                                    <td class="text-right tabular" data-out="total">{{ $amount($pay['total_daily_rate']) }}</td>
                                    <td class="!px-1">{!! $text($slip, 'covered', 'Date period covered', 'w-28') !!}</td>
                                    <td class="!px-1">{!! $input($slip, 'days', 'No. of days', 'w-14') !!}</td>
                                    <td class="text-right font-medium tabular" data-out="gross">{{ $amount($pay['gross']) }}</td>
                                    <td class="!px-1">{!! $input($slip, 'late', 'Late minutes', 'w-14', 'numeric') !!}</td>
                                    <td class="text-right tabular text-muted" data-out="per_minute">{{ $amount($pay['rate_per_minute']) }}</td>
                                    <td class="text-right tabular" data-out="late_amount">{{ $amount($pay['late']) }}</td>
                                    <td class="!px-1">{!! $input($slip, 'ot', 'OT hours', 'w-14') !!}</td>
                                    <td class="text-right tabular text-muted" data-out="hourly">{{ $amount($pay['hourly_rate']) }}</td>
                                    <td class="!px-1">{!! $input($slip, 'ot_rest', 'OT hours on rest days or special holidays', 'w-14') !!}</td>
                                    <td class="text-right tabular text-muted" data-out="hourly_rest">{{ $amount($pay['hourly_rest_rate']) }}</td>
                                    <td class="text-right tabular" data-out="overtime">{{ $amount($pay['overtime']) }}</td>
                                    <td class="text-right font-medium tabular" data-out="after">{{ $amount($pay['gross_after_late']) }}</td>
                                    <td class="!px-1">{!! $input($slip, 'sss', 'SSS', 'w-20') !!}</td>
                                    <td class="!px-1">{!! $input($slip, 'hdmf', 'HDMF', 'w-20') !!}</td>
                                    <td class="!px-1">{!! $input($slip, 'cmpeai', 'CMPEAI', 'w-20') !!}</td>
                                    <td class="text-right font-semibold tabular" data-out="net">{{ $amount($pay['net']) }}</td>
                                    <td class="!px-1">{!! $input($slip, 'absences', 'Absences', 'w-14') !!}</td>
                                    <td class="!px-1">{!! $input($slip, 'tardiness', 'Tardiness', 'w-14', 'numeric') !!}</td>
                                    <td class="text-right tabular text-muted" data-out="excess">{{ $amount($pay['excess']) }}</td>
                                    <td>
                                        <a class="btn-ghost btn-sm" href="{{ route('payroll.payslips.print', $slip) }}" title="Print payslip"><x-icon name="printer" class="size-3.5" label="Print payslip" /></a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="sticky bottom-0 bg-surface-2 font-semibold">
                            <tr>
                                <td class="sticky left-0 z-[1] bg-surface-2">Total ({{ $slips->count() }})</td>
                                <td colspan="7"></td>
                                <td class="text-right tabular" data-total="days"></td>
                                <td class="text-right tabular" data-total="gross"></td>
                                <td colspan="2"></td>
                                <td class="text-right tabular" data-total="late_amount"></td>
                                <td colspan="4"></td>
                                <td class="text-right tabular" data-total="overtime"></td>
                                <td class="text-right tabular" data-total="after"></td>
                                <td class="text-right tabular" data-total="sss"></td>
                                <td class="text-right tabular" data-total="hdmf"></td>
                                <td class="text-right tabular" data-total="cmpeai"></td>
                                <td class="text-right tabular" data-total="net"></td>
                                <td colspan="2"></td>
                                <td class="text-right tabular" data-total="excess"></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line p-4">
                    <p class="text-xs text-muted">Type days, late minutes, overtime hours and the deductions; everything else is computed as in the payroll workbook. SSS / HDMF / CMPEAI changed here apply to this period only. Every row is saved together or not at all.</p>
                    <button class="btn-primary">Save payroll</button>
                </div>
            </form>
        @endif
    </div>

    @unless ($released)
        <div class="mt-6 flex flex-wrap gap-2">
            @if ($slips->isNotEmpty())
                <form method="post" action="{{ route('payroll.payslips.destroy-all', $period) }}" data-confirm="Remove ALL workers from this payroll? This cannot be undone.">
                    @csrf
                    @method('DELETE')
                    <button class="btn-danger btn-sm"><x-icon name="trash" class="size-3.5" /> Remove all workers</button>
                </form>
            @else
                <form method="post" action="{{ route('payroll.destroy', $period) }}" data-confirm="Delete {{ $period->name }}?">
                    @csrf
                    @method('DELETE')
                    <button class="btn-danger btn-sm"><x-icon name="trash" class="size-3.5" /> Delete period</button>
                </form>
            @endif
        </div>
    @endunless
</x-layouts.app>
