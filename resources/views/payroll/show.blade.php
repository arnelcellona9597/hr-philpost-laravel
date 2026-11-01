@php
    use App\Support\HrDate;
    use App\Support\Money;

    $symbol = $settings->currency_symbol ?: '₱';
    $released = $period->isReleased();
    $corrected = $slips->where('corrected_after_release', true)->count();
    $posted = collect(json_decode((string) old('grid'), true) ?: [])->pluck('values', 'id');
    $amount = function ($slip, string $kind, string $label, array $items) use ($posted): string {
        $saved = array_column($items, 'amount_centavos', 'label')[$label] ?? 0;

        return (string) ($posted[$slip->id]["{$kind}:{$label}"] ?? number_format($saved / 100, 2, '.', ''));
    };
@endphp

<x-layouts.app :title="$period->name" :heading="$period->name" :back="['href' => route('payroll.index'), 'label' => 'Payroll']" :description="HrDate::format($period->period_start).' – '.HrDate::format($period->period_end).' · Pay date '.HrDate::format($period->pay_date).' · '.$period->code">
    <x-slot:actions>
        <span class="{{ $released ? 'badge-success' : 'badge-warning' }}">{{ $period->status->value }}</span>
        <a class="btn-secondary" href="{{ route('payroll.edit', $period) }}"><x-icon name="edit" /> Edit period</a>
        @if ($slips->isNotEmpty())
            <a class="btn-secondary" href="{{ route('payroll.print', $period) }}"><x-icon name="printer" /> Print all</a>
            <a class="btn-secondary" href="{{ route('payroll.export', $period) }}"><x-icon name="download" /> Excel</a>
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

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4"><p class="text-xs text-muted">Payslips</p><p class="mt-1 text-2xl font-semibold tabular">{{ $slips->count() }}</p></div>
        <div class="card p-4"><p class="text-xs text-muted">Total gross</p><p class="mt-1 text-2xl font-semibold tabular">{{ Money::format($slips->sum('gross_pay_centavos'), $symbol) }}</p></div>
        <div class="card p-4"><p class="text-xs text-muted">Total net</p><p class="mt-1 text-2xl font-semibold tabular">{{ Money::format($slips->sum('net_pay_centavos'), $symbol) }}</p></div>
        <div class="card p-4"><p class="text-xs text-muted">Missing earnings</p><p class="mt-1 text-2xl font-semibold tabular">{{ $slips->where('gross_pay_centavos', 0)->count() }}</p></div>
    </div>

    <div class="mb-6 grid gap-4 lg:grid-cols-2">
        @unless ($released)
            <form method="post" action="{{ route('payroll.generate', $period) }}" class="card space-y-3 p-4">
                @csrf
                <h2 class="text-sm font-semibold">Generate payslips</h2>
                <p class="text-xs text-muted">Creates an empty payslip for each active employee who doesn't have one in this period yet. Leave the filters empty for everyone.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <select class="input" name="branch[]" multiple size="3" aria-label="Branches">
                        @foreach ($branches as $branch)
                            <option value="{{ $branch['value'] }}">{{ $branch['label'] }}</option>
                        @endforeach
                    </select>
                    <div>
                        @foreach ($classifications as $classification)
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="classification[]" value="{{ $classification }}" class="size-4" /> {{ $classification }}</label>
                        @endforeach
                    </div>
                </div>
                <button class="btn-secondary btn-sm"><x-icon name="plus" class="size-3.5" /> Generate</button>
            </form>
        @endunless
        <div class="card space-y-3 p-4">
            <h2 class="text-sm font-semibold">Import amounts from Excel</h2>
            <p class="text-xs text-muted">
                Download the Excel file, fill in the amounts, and upload it. Rows are matched by Employee ID{{ $released ? '.' : '; employees without a payslip get one.' }} Everything is checked before saving.
            </p>
            <form method="post" action="{{ route('payroll.import.store', $period) }}" enctype="multipart/form-data" class="flex flex-wrap gap-2">
                @csrf
                <input class="input max-w-xs" type="file" name="file" accept=".xlsx" required aria-label="Payroll Excel file" />
                <button class="btn-secondary btn-sm"><x-icon name="upload" class="size-3.5" /> Check file</button>
            </form>
            @if (! $released && $slips->isNotEmpty())
                <form method="post" action="{{ route('payroll.release', $period) }}" data-confirm="Release {{ $period->name }}? {{ $slips->count() }} payslip(s) will be final and emailed to employees." class="flex flex-wrap items-center gap-3 border-t border-line pt-3">
                    @csrf
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="notify" value="1" checked class="size-4" /> Email each employee their payslip (PDF)</label>
                    <button class="btn-primary btn-sm"><x-icon name="send" class="size-3.5" /> Release payroll</button>
                </form>
            @endif
        </div>
    </div>

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
            <x-empty-state title="No payslips yet" text="Use “Generate payslips” above, or import an Excel file." />
        @else
            <form method="post" action="{{ route('payroll.grid', $period) }}" id="payroll-grid" data-dirty-guard data-symbol="{{ $symbol }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="grid" id="grid-input" />
                <div class="max-h-[70vh] overflow-auto">
                    <table class="table text-xs [&_td]:align-middle">
                        <thead class="sticky top-0 z-10">
                            <tr>
                                <th class="sticky left-0 z-20 min-w-48">Employee</th>
                                @foreach ($labels['earnings'] as $label)
                                    <th class="min-w-28 text-right !normal-case">{{ $label }}</th>
                                @endforeach
                                <th class="min-w-28 text-right">Gross</th>
                                @foreach ($labels['deductions'] as $label)
                                    <th class="min-w-28 text-right !normal-case">{{ $label }}</th>
                                @endforeach
                                <th class="min-w-28 text-right">Deductions</th>
                                <th class="min-w-28 text-right">Net pay</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($slips as $slip)
                                <tr data-row="{{ $slip->id }}">
                                    <td class="sticky left-0 z-[1] bg-surface">
                                        <a class="font-medium hover:text-primary-ink" href="{{ route('payroll.payslips.show', $slip) }}">{{ $slip->full_name }}</a>
                                        <span class="block font-mono text-[11px] text-muted">{{ $slip->employee_code }}</span>
                                        @if ($slip->corrected_after_release)
                                            <span class="badge-danger">corrected</span>
                                        @endif
                                    </td>
                                    @foreach ($labels['earnings'] as $label)
                                        <td class="!px-1"><input class="input py-1 text-right tabular" inputmode="decimal" value="{{ $amount($slip, 'e', $label, $slip->earnings) }}" data-kind="e" data-item="e:{{ $label }}" aria-label="{{ $label }} for {{ $slip->full_name }}" /></td>
                                    @endforeach
                                    <td class="text-right font-medium tabular" data-gross>{{ Money::format($slip->gross_pay_centavos, $symbol) }}</td>
                                    @foreach ($labels['deductions'] as $label)
                                        <td class="!px-1"><input class="input py-1 text-right tabular" inputmode="decimal" value="{{ $amount($slip, 'd', $label, $slip->deductions) }}" data-kind="d" data-item="d:{{ $label }}" aria-label="{{ $label }} for {{ $slip->full_name }}" /></td>
                                    @endforeach
                                    <td class="text-right tabular" data-ded>{{ Money::format($slip->total_deductions_centavos, $symbol) }}</td>
                                    <td class="text-right font-semibold tabular" data-net>{{ Money::format($slip->net_pay_centavos, $symbol) }}</td>
                                    <td class="whitespace-nowrap">
                                        <a class="btn-ghost btn-sm" href="{{ route('payroll.payslips.print', $slip) }}" title="Print"><x-icon name="printer" class="size-3.5" label="Print payslip" /></a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line p-4">
                    <p class="text-xs text-muted">Amounts in pesos, up to 2 decimals. Totals are added up automatically; every row is saved together or not at all.</p>
                    <button class="btn-primary">Save all payslips</button>
                </div>
            </form>
        @endif
    </div>

    @unless ($released)
        <div class="mt-6 flex flex-wrap gap-2">
            @if ($slips->isNotEmpty())
                <form method="post" action="{{ route('payroll.payslips.destroy', $period) }}" data-confirm="Delete ALL payslips in this period? This cannot be undone.">
                    @csrf
                    @method('DELETE')
                    <button class="btn-danger btn-sm"><x-icon name="trash" class="size-3.5" /> Delete all payslips</button>
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
