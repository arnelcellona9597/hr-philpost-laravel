@php
    use App\Support\HrDate;
    use App\Support\Money;

    $void = $contract->isVoid();
    $content = $contract->content;
@endphp

<x-layouts.app :title="'Contract '.$contract->control_number" :heading="$contract->control_number" :back="['href' => route('contracts.index'), 'label' => 'Contracts']" :description="$contract->template_name.' · '.$contract->employee_name">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('contracts.docx', $contract) }}" download><x-icon name="download" /> Word (.docx)</a>
        <a class="btn-secondary" href="{{ route('contracts.pdf', $contract) }}" download><x-icon name="download" /> PDF</a>
        <a class="btn-primary" href="{{ route('contracts.print', $contract) }}" target="_blank" rel="noopener"><x-icon name="printer" /> Print</a>
    </x-slot:actions>

    @if ($errors->has('form'))
        <div class="mb-4 flex items-start gap-3 rounded-xl border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">
            <x-icon name="alert" class="mt-0.5 size-4 shrink-0" />
            <span>{{ $errors->first('form') }}</span>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="min-w-0 rounded-xl border border-line bg-surface-2 p-3 sm:p-6">
            <x-certificates.certificate-sheet :paper-size="$content['paperSize']" :margin="$content['margin']" :void="$void">{!! $body !!}</x-certificates.certificate-sheet>
        </div>
        <aside class="space-y-4">
            <section class="card p-5">
                <h2 class="text-sm font-semibold">Details</h2>
                <dl class="mt-3 space-y-2.5 text-sm">
                    <div><dt class="text-xs text-muted">Status</dt><dd><span class="{{ $void ? 'badge-danger' : 'badge-success' }}">{{ $contract->status->value }}</span></dd></div>
                    <div><dt class="text-xs text-muted">Worker</dt><dd><a class="text-primary-ink hover:underline" href="{{ route('employees.show', $contract->employee_id) }}">{{ $contract->employee_name }}</a></dd></div>
                    <div><dt class="text-xs text-muted">Position · office</dt><dd>{{ $contract->position }} · {{ $contract->office }}</dd></div>
                    <div><dt class="text-xs text-muted">Place of service</dt><dd>{{ $contract->place_of_service }}</dd></div>
                    <div><dt class="text-xs text-muted">Term</dt><dd>{{ HrDate::format($contract->term_start) }} – {{ HrDate::format($contract->term_end) }}</dd></div>
                    <div><dt class="text-xs text-muted">Daily wage</dt><dd>{{ Money::format($contract->daily_wage_centavos) }}@if ($contract->second_wage_centavos), then {{ Money::format($contract->second_wage_centavos) }} from {{ HrDate::format($contract->second_wage_from) }}@endif</dd></div>
                    <div><dt class="text-xs text-muted">Issued by</dt><dd>{{ $contract->issued_by_name }} · {{ HrDate::format($contract->created_at) }}</dd></div>
                    @if ($void)
                        <div><dt class="text-xs text-muted">Voided</dt><dd>{{ $contract->voided_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }} — {{ $contract->void_reason }}</dd></div>
                    @endif
                </dl>
            </section>
            <section class="card space-y-2 p-5">
                <h2 class="text-sm font-semibold">Actions</h2>
                @unless ($void)
                    <details class="rounded-lg border border-line p-3">
                        <summary class="cursor-pointer text-sm font-medium text-danger">Void this contract</summary>
                        <form method="post" action="{{ route('contracts.void', $contract) }}" class="mt-3 space-y-2" data-confirm="Void this contract? It will print with a VOID mark and cannot be made valid again.">
                            @csrf
                            <label class="label" for="reason">Reason</label>
                            <input class="input" id="reason" name="reason" required maxlength="300" placeholder="e.g. wrong dates, reissued" />
                            <button class="btn-danger w-full"><x-icon name="ban" /> Void contract</button>
                        </form>
                    </details>
                @endunless
                @can('delete', $contract)
                    <form method="post" action="{{ route('contracts.destroy', $contract) }}" data-confirm="Delete this contract record? This cannot be undone.">
                        @csrf
                        @method('DELETE')
                        <button class="btn-ghost w-full text-danger"><x-icon name="trash" /> Delete record</button>
                    </form>
                @endcan
            </section>
        </aside>
    </div>
</x-layouts.app>
