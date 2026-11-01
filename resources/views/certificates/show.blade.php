@php
    use App\Support\HrDate;

    $void = $certificate->isVoid();
    $content = $certificate->content;
@endphp

<x-layouts.app :title="'Certificate '.$certificate->control_number" :heading="$certificate->control_number" :back="['href' => route('certificates.index'), 'label' => 'Certificates']" :description="$certificate->template_name.' · '.$certificate->employee_name">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('certificates.pdf', $certificate) }}" download><x-icon name="download" /> Download PDF</a>
        <a class="btn-primary" href="{{ route('certificates.print', $certificate) }}" target="_blank" rel="noopener"><x-icon name="printer" /> Print</a>
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
                    <div><dt class="text-xs text-muted">Status</dt><dd><span class="{{ $void ? 'badge-danger' : 'badge-success' }}">{{ $certificate->status->value }}</span></dd></div>
                    <div><dt class="text-xs text-muted">Employee</dt><dd><a class="text-primary-ink hover:underline" href="{{ route('employees.show', $certificate->employee_id) }}">{{ $certificate->employee_name }}</a></dd></div>
                    <div><dt class="text-xs text-muted">Certificate</dt><dd>{{ $certificate->template_name }}</dd></div>
                    <div><dt class="text-xs text-muted">Purpose</dt><dd>{{ $certificate->purpose ?: 'Whatever legal purpose it may serve' }}</dd></div>
                    <div><dt class="text-xs text-muted">Date issued</dt><dd>{{ HrDate::format($certificate->issued_date) }}</dd></div>
                    <div><dt class="text-xs text-muted">Issued by</dt><dd>{{ $certificate->issued_by_name }}</dd></div>
                    @if ($void)
                        <div><dt class="text-xs text-muted">Voided</dt><dd>{{ $certificate->voided_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }} — {{ $certificate->void_reason }}</dd></div>
                    @endif
                </dl>
            </section>
            <section class="card space-y-2 p-5">
                <h2 class="text-sm font-semibold">Actions</h2>
                <a class="btn-secondary w-full" href="{{ route('certificates.create', ['employee' => $certificate->employee_id, 'template' => $certificate->template_id]) }}"><x-icon name="copy" /> Issue another like this</a>
                @unless ($void)
                    <details class="rounded-lg border border-line p-3">
                        <summary class="cursor-pointer text-sm font-medium text-danger">Void this certificate</summary>
                        <form method="post" action="{{ route('certificates.void', $certificate) }}" class="mt-3 space-y-2" data-confirm="Void this certificate? It will print with a VOID mark and cannot be made valid again.">
                            @csrf
                            <label class="label" for="reason">Reason</label>
                            <input class="input" id="reason" name="reason" required maxlength="300" placeholder="e.g. wrong purpose, reissued" />
                            <button class="btn-danger w-full"><x-icon name="ban" /> Void certificate</button>
                        </form>
                    </details>
                @endunless
                @can('delete', $certificate)
                    <form method="post" action="{{ route('certificates.destroy', $certificate) }}" data-confirm="Delete this certificate record? This cannot be undone.">
                        @csrf
                        @method('DELETE')
                        <button class="btn-ghost w-full text-danger"><x-icon name="trash" /> Delete record</button>
                    </form>
                @endcan
            </section>
        </aside>
    </div>
</x-layouts.app>
