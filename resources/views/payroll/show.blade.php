@php
    use App\Support\HrDate;
    use App\Support\Money;

    $symbol = $settings->currency_symbol ?: '₱';
    $released = $period->isReleased();
    $draft = $period->isDraft();
    // Drafts are edited by HR; released payroll is corrected only by a System Admin; Reviewed and Approved are read-only.
    $editable = $draft || ($released && Gate::allows('correctReleased', $period));
    $lock = $editable ? '' : ' disabled';
    $stamp = fn ($at, $by): string => $at ? $at->timezone('Asia/Manila')->format('M j, Y g:i A').($by ? ' by '.$by->name : '') : '';
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
    $input = fn ($slip, string $key, string $label, string $width = 'w-16', string $mode = 'decimal'): string => '<input class="input '.$width.' px-1.5 py-1 text-right tabular" inputmode="'.$mode.'" data-in="'.$key.'" value="'.e($value($slip, $key)).'" aria-label="'.e($label.' for '.$slip->full_name).'"'.$lock.' />';
    $text = fn ($slip, string $key, string $label, string $width): string => '<input class="input '.$width.' px-1.5 py-1" data-in="'.$key.'" value="'.e($value($slip, $key)).'" aria-label="'.e($label.' for '.$slip->full_name).'"'.$lock.' />';
@endphp

<x-layouts.app :title="$period->name" :heading="$period->name" :back="['href' => route('payroll.index'), 'label' => 'Payroll']" :description="'COS payroll · '.$period->titleRange().($period->batch ? ' · Batch '.$period->batch : '').' · Pay date '.HrDate::format($period->pay_date).' · '.$period->code">
    <x-slot:actions>
        <span class="{{ $period->status->badge() }}">{{ $period->status->value }}</span>
        <a class="btn-secondary" href="{{ route('payroll.monthly', [$period->period_start->year, $period->period_start->month]) }}"><x-icon name="sheet" /> Monthly summary</a>
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
            {{ $withoutRate }} worker(s) have no daily rate: no contract rate in their pay history and no wage order for their station's region. Add the rate (Employees → profile → Pay history) or the region's wage order (Wage Orders), then use <strong>Refresh rates</strong>.
        </div>
    @endif

    {{-- Approval steps: Draft → Reviewed → Approved → Released. --}}
    <section class="card mb-6 p-4" aria-labelledby="workflow-title">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 id="workflow-title" class="text-sm font-semibold">Approval</h2>
                <ol class="mt-2 flex flex-wrap gap-2 text-xs">
                    @foreach (\App\Enums\PayrollStatus::cases() as $index => $step)
                        <li @class(['rounded-full px-2.5 py-1', 'bg-primary-soft font-semibold text-primary-ink' => $period->status === $step, 'bg-surface-2 text-muted' => $period->status !== $step])>{{ $index + 1 }}. {{ $step->value }}</li>
                    @endforeach
                </ol>
                <dl class="mt-2 space-y-0.5 text-xs text-muted">
                    @if ($period->reviewed_at) <div>Reviewed {{ $stamp($period->reviewed_at, $period->reviewedBy) }}</div> @endif
                    @if ($period->approved_at) <div>Approved {{ $stamp($period->approved_at, $period->approvedBy) }}</div> @endif
                    @if ($period->released_at) <div>Released {{ $stamp($period->released_at, $period->releasedBy) }}</div> @endif
                </dl>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($draft && $slips->isNotEmpty())
                    @can('review', $period)
                        <form method="post" action="{{ route('payroll.review', $period) }}" data-confirm="Mark {{ $period->name }} as reviewed? It becomes read-only until a System Admin approves it or sends it back to draft.">
                            @csrf
                            <button class="btn-primary btn-sm"><x-icon name="check" class="size-3.5" /> Mark as reviewed</button>
                        </form>
                    @endcan
                @endif
                @if ($period->status === \App\Enums\PayrollStatus::Reviewed)
                    @can('approve', $period)
                        <form method="post" action="{{ route('payroll.approve', $period) }}" class="flex flex-wrap items-center gap-2" data-confirm="Approve {{ $period->name }}?">
                            @csrf
                            @if ($review['below'] > 0)
                                <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="confirm_below_minimum" value="1" class="size-4" /> I confirm the {{ $review['below'] }} contract rate(s) below the regional minimum are correct</label>
                            @endif
                            <button class="btn-primary btn-sm"><x-icon name="check" class="size-3.5" /> Approve</button>
                        </form>
                    @else
                        <p class="text-xs text-muted">Waiting for a System Admin to approve.</p>
                    @endcan
                @endif
                @if ($period->status === \App\Enums\PayrollStatus::Approved)
                    @can('release', $period)
                        <form method="post" action="{{ route('payroll.release', $period) }}" class="flex flex-wrap items-center gap-2" data-confirm="Release {{ $period->name }}? {{ $slips->count() }} payslip(s) will be final and emailed to the workers.">
                            @csrf
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="notify" value="1" checked class="size-4" /> Email each worker their payslip (PDF)</label>
                            <button class="btn-primary btn-sm"><x-icon name="send" class="size-3.5" /> Release payroll</button>
                        </form>
                    @else
                        <p class="text-xs text-muted">Waiting for a System Admin to release.</p>
                    @endcan
                @endif
                @if (! $draft && ! $released)
                    @can('returnToDraft', $period)
                        <form method="post" action="{{ route('payroll.return-to-draft', $period) }}" data-confirm="Send {{ $period->name }} back to draft? Its review and approval are cleared.">
                            @csrf
                            <button class="btn-secondary btn-sm">Return to draft</button>
                        </form>
                    @endcan
                @endif
            </div>
        </div>
    </section>

    {{-- What the reviewer checks: the regional minimum, premium and adjusted rate behind each worker's pay. --}}
    @if ($slips->isNotEmpty())
        <section class="card mb-6 overflow-hidden" aria-labelledby="rates-title">
            <div class="border-b border-line px-4 py-3">
                <h2 id="rates-title" class="text-sm font-semibold">Regional minimum wage and premium</h2>
                <p class="text-xs text-muted">Each worker is paid their contract rate from pay history; workers without one get the regional minimum of their station's region on {{ HrDate::format($period->period_end) }}. The premium follows each worker's contract (default set in Contracts → Officials and wages); it is agency policy, not a statutory requirement.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="table text-xs">
                    <thead><tr><th>Region</th><th>Wage order in force</th><th class="text-right">Regional minimum</th><th>Premium</th><th class="text-right">Adjusted daily rate</th><th class="text-right">Workers</th><th class="text-right">On regional minimum</th><th class="text-right">Below minimum</th></tr></thead>
                    <tbody>
                        @foreach ($review['regions'] as $region)
                            <tr>
                                <td class="font-medium">{{ $region['region'] }}</td>
                                <td>
                                    @if ($region['order'])
                                        <a class="hover:text-primary-ink" href="{{ route('wage-orders.show', $region['order']) }}">{{ $region['order']->order_number ?: 'Wage order' }}</a>
                                        <span class="text-muted">· effective {{ HrDate::format($region['order']->effective_on) }}</span>
                                    @else
                                        <span class="text-danger">None encoded</span>
                                    @endif
                                </td>
                                <td class="text-right tabular">{{ $region['minimum'] !== null ? Money::format($region['minimum'], $symbol) : '—' }}</td>
                                <td>{{ implode(', ', $region['premiums']) }}</td>
                                <td class="text-right tabular">{{ $region['adjusted'] !== null ? Money::format($region['adjusted'], $symbol) : '—' }}</td>
                                <td class="text-right tabular">{{ $region['workers'] }}</td>
                                <td class="text-right tabular">{{ $region['regional'] ?: '' }}</td>
                                <td @class(['text-right tabular', 'font-semibold text-danger' => $region['below'] > 0])>{{ $region['below'] ?: '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4"><p class="text-xs text-muted">COS workers</p><p class="mt-1 text-2xl font-semibold tabular">{{ $slips->count() }}</p></div>
        <div class="card p-4"><p class="text-xs text-muted">Total gross after late</p><p class="mt-1 text-2xl font-semibold tabular">{{ Money::format($slips->sum('gross_pay_centavos') - $slips->sum(fn ($slip) => $slip->cosPay()['late']), $symbol) }}</p></div>
        <div class="card p-4"><p class="text-xs text-muted">Total net pay</p><p class="mt-1 text-2xl font-semibold tabular">{{ Money::format($slips->sum('net_pay_centavos'), $symbol) }}</p></div>
        <div class="card p-4"><p class="text-xs text-muted">No days entered</p><p class="mt-1 text-2xl font-semibold tabular">{{ $slips->where('gross_pay_centavos', 0)->count() }}</p></div>
    </div>

    @if ($draft)
        <div class="mb-6 grid gap-4 lg:grid-cols-2">
            <form method="post" action="{{ route('payroll.generate', $period) }}" class="card space-y-3 p-4">
                @csrf
                <h2 class="text-sm font-semibold">Add COS workers</h2>
                <p class="text-xs text-muted">Adds every active COS worker who isn't in this payroll yet, with their contract rate (or their region's minimum), premium and usual SSS / HDMF / CMPEAI. Pick branches to add only those; leave empty for all.</p>
                <select class="input" name="branch[]" multiple size="3" aria-label="Branches">
                    @foreach ($branches as $branch)
                        <option value="{{ $branch['value'] }}">{{ $branch['label'] }}</option>
                    @endforeach
                </select>
                <button class="btn-secondary btn-sm"><x-icon name="plus" class="size-3.5" /> Add workers</button>
            </form>
            <div class="card space-y-3 p-4">
                <h2 class="text-sm font-semibold">Rates</h2>
                <form method="post" action="{{ route('payroll.refresh-rates', $period) }}" class="flex flex-wrap items-center gap-3">
                    @csrf
                    <p class="flex-1 text-xs text-muted">After a wage order, a pay-history fix or a change of station, read every worker's daily rate, premium and regional minimum again for {{ HrDate::format($period->period_end) }}.</p>
                    <button class="btn-secondary btn-sm" @disabled($slips->isEmpty())><x-icon name="history" class="size-3.5" /> Refresh rates</button>
                </form>
            </div>
        </div>
    @endif

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
                                <th>Region</th>
                                <th>Rate source</th>
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
                                    <td>{{ $slip->region ?: '—' }}</td>
                                    <td>
                                        <span @class(['text-muted' => $slip->rate_source === 'contract', 'text-primary-ink' => $slip->rate_source === 'regional', 'text-danger' => ! $slip->rate_source])>{{ $slip->rateSourceLabel() }}</span>
                                        @if ($slip->isBelowMinimum())
                                            <span class="badge-danger" title="Regional minimum {{ $amount((int) $slip->regional_minimum_centavos) }}">below minimum</span>
                                        @endif
                                    </td>
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
                                <td colspan="9"></td>
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
                    @if ($editable)
                        <p class="text-xs text-muted">Type days, late minutes, overtime hours and the deductions; everything else is computed as in the payroll workbook. SSS / HDMF / CMPEAI changed here apply to this period only. Every row is saved together or not at all.</p>
                        <button class="btn-primary">Save payroll</button>
                    @else
                        <p class="text-xs text-muted">This payroll is {{ $period->status->value }}, so it can't be changed{{ $released ? '' : '. A System Admin can return it to draft' }}.</p>
                    @endif
                </div>
            </form>
        @endif
    </div>

    @if ($draft)
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
    @endif
</x-layouts.app>
