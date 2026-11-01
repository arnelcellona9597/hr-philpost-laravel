<x-layouts.app title="Payslip template" heading="Payslip template" description="What printed and emailed payslips show, and which pay items HR fills in." :back="['href' => route('payroll.index'), 'label' => 'Payroll']">
    @if ($errors->has('form'))
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first('form') }}</div>
    @endif

    <div class="grid gap-6 2xl:grid-cols-2">
        <form method="post" action="{{ route('payroll.settings') }}" class="space-y-6" data-dirty-guard>
            @csrf
            @method('PUT')
            <fieldset class="card grid gap-4 p-5 sm:grid-cols-2">
                <legend class="px-1 text-sm font-semibold">Header</legend>
                <div><label class="label" for="title">Title</label><input class="input" id="title" name="title" maxlength="60" value="{{ old('title', $settings->title) }}" /></div>
                <div><label class="label" for="company_name">Company name</label><input class="input" id="company_name" name="company_name" value="{{ old('company_name', $settings->company_name) }}" placeholder="{{ $siteName }}" /></div>
                <div class="sm:col-span-2"><label class="label" for="address_lines">Address / extra header lines (one per line)</label><textarea class="input min-h-20" id="address_lines" name="address_lines">{{ old('address_lines', $settings->address_lines) }}</textarea></div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="show_logo" value="1" @checked($settings->show_logo) class="size-4" /> Show the logo (PNG/JPG from Branding)</label>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="label" for="currency_symbol">Currency</label><input class="input" id="currency_symbol" name="currency_symbol" maxlength="5" value="{{ old('currency_symbol', $settings->currency_symbol) }}" /></div>
                    <div>
                        <label class="label" for="paper_size">Paper</label>
                        <select class="input" id="paper_size" name="paper_size">
                            @foreach (['A4', 'Letter', 'Half-Letter'] as $paper)
                                <option @selected($paper === old('paper_size', $settings->paper_size))>{{ $paper }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </fieldset>
            <fieldset class="card grid gap-4 p-5 sm:grid-cols-2">
                <legend class="px-1 text-sm font-semibold">Pay items</legend>
                <div>
                    <label class="label" for="earning_items">Earnings (one per line)</label>
                    <textarea class="input min-h-36" id="earning_items" name="earning_items">{{ old('earning_items', implode("\n", $settings->earning_items ?? [])) }}</textarea>
                </div>
                <div>
                    <label class="label" for="deduction_items">Deductions (one per line)</label>
                    <textarea class="input min-h-36" id="deduction_items" name="deduction_items">{{ old('deduction_items', implode("\n", $settings->deduction_items ?? [])) }}</textarea>
                </div>
                <p class="field-help sm:col-span-2">These are the columns of the payroll grid and Excel sheet. Renaming an item doesn't change saved payslips; their old item stays on them as an extra line.</p>
            </fieldset>
            <fieldset class="card p-5">
                <legend class="px-1 text-sm font-semibold">Employee details shown</legend>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach ($shownFields as $field => $label)
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="show_{{ $field }}" value="1" @checked($settings->shown_fields[$field] ?? false) class="size-4" /> {{ $label }}</label>
                    @endforeach
                </div>
            </fieldset>
            <fieldset class="card grid gap-4 p-5 sm:grid-cols-2">
                <legend class="px-1 text-sm font-semibold">Signatories &amp; footer</legend>
                <div><label class="label" for="prepared_by_name">Prepared by</label><input class="input" id="prepared_by_name" name="prepared_by_name" value="{{ old('prepared_by_name', $settings->prepared_by_name) }}" /></div>
                <div><label class="label" for="prepared_by_title">Position</label><input class="input" id="prepared_by_title" name="prepared_by_title" value="{{ old('prepared_by_title', $settings->prepared_by_title) }}" /></div>
                <div><label class="label" for="certified_by_name">Certified correct by</label><input class="input" id="certified_by_name" name="certified_by_name" value="{{ old('certified_by_name', $settings->certified_by_name) }}" /></div>
                <div><label class="label" for="certified_by_title">Position</label><input class="input" id="certified_by_title" name="certified_by_title" value="{{ old('certified_by_title', $settings->certified_by_title) }}" /></div>
                <div class="sm:col-span-2"><label class="label" for="footer_note">Footer note</label><textarea class="input" id="footer_note" name="footer_note">{{ old('footer_note', $settings->footer_note) }}</textarea></div>
            </fieldset>
            <button class="btn-primary">Save template</button>
        </form>
        <section>
            <p class="mb-2 text-sm font-semibold">Preview {{ $real ? "({$real->full_name})" : '(sample data)' }}</p>
            <div class="overflow-x-auto rounded-xl bg-surface-2 p-4">
                <x-payroll.payslip :settings="$settings" :company-name="$companyName" :logo-url="$logoUrl" :period="$period" :slip="$slip" />
            </div>
            @if ($real)
                <a class="mt-2 inline-block text-sm text-primary-ink hover:underline" href="{{ route('payroll.payslips.pdf', $real) }}">Open this payslip as PDF</a>
            @endif
        </section>
    </div>
</x-layouts.app>
