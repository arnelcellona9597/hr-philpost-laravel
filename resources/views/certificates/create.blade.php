<x-layouts.app title="Issue certificate" heading="Issue a certificate" :back="['href' => route('certificates.index'), 'label' => 'Certificates']" description="Choose the employee and the purpose. The preview shows exactly what will be printed.">
    @if ($errors->has('form'))
        <div class="mb-4 flex items-start gap-3 rounded-xl border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">
            <x-icon name="alert" class="mt-0.5 size-4 shrink-0" />
            <span>{{ $errors->first('form') }}</span>
        </div>
    @endif

    <form method="post" action="{{ route('certificates.store') }}" class="grid gap-6 xl:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]" id="issue-form" data-preview-url="{{ route('certificates.preview') }}">
        @csrf
        <div class="space-y-5">
            <section class="card space-y-4 p-5">
                <h2 class="font-semibold">1. Employee</h2>
                <div>
                    <label class="label" for="employee">Employee</label>
                    <select class="input" id="employee" name="employee" required size="6" data-searchable>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee['value'] }}" @selected($employee['value'] === $employeeId)>{{ $employee['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            </section>

            <section class="card space-y-3 p-5">
                <h2 class="font-semibold">2. Certificate</h2>
                @if ($templates->isEmpty())
                    <p class="text-sm text-muted">No active templates. <a class="text-primary-ink underline" href="{{ route('certificates.templates.index') }}">Create or activate one</a>.</p>
                @else
                    <fieldset class="space-y-2">
                        <legend class="sr-only">Certificate template</legend>
                        @foreach ($templates as $template)
                            <label class="flex cursor-pointer gap-3 rounded-lg border border-line p-3 transition-colors hover:border-line-strong has-[:checked]:border-primary-ink has-[:checked]:bg-primary-soft">
                                <input type="radio" name="template" value="{{ $template->id }}" @checked($template->id === $templateId) class="mt-1 accent-[var(--primary)]" required />
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium">{{ $template->name }}</span>
                                    @if ($template->description)
                                        <span class="block text-xs text-muted">{{ $template->description }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </fieldset>
                @endif
            </section>

            <section class="card space-y-4 p-5">
                <h2 class="font-semibold">3. Details</h2>
                <div>
                    <label class="label" for="purpose">Purpose / requested for</label>
                    <input class="input" id="purpose" name="purpose" maxlength="200" value="{{ $purpose }}" placeholder="e.g. housing loan application with Pag-IBIG" />
                    <p class="field-help">Fills @{{purpose}}. Leave blank for “whatever legal purpose it may serve”.</p>
                </div>
                <div>
                    <label class="label" for="issued_date">Date issued</label>
                    <input class="input" id="issued_date" name="issued_date" type="date" required value="{{ $issuedDate }}" />
                </div>
                <button class="btn-primary w-full" @disabled($templates->isEmpty())><x-icon name="award" /> Issue certificate</button>
                <p class="text-xs text-muted">A control number is assigned when you issue it. The certificate is saved and can be reprinted anytime.</p>
            </section>
        </div>

        <section class="min-w-0" aria-labelledby="preview-title">
            <div class="mb-2 flex items-center justify-between gap-3">
                <h2 id="preview-title" class="text-sm font-semibold">Preview</h2>
                <span class="text-xs text-muted" data-preview-status aria-live="polite"></span>
            </div>
            <div class="rounded-xl border border-line bg-surface-2 p-3 sm:p-6" data-preview-box>
                <div class="mx-auto origin-top-left" data-preview-scale>
                    <article class="cert-paper" data-preview-paper></article>
                </div>
            </div>
        </section>
    </form>
</x-layouts.app>
