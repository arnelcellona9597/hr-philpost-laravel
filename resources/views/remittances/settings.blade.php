@php
    use App\Enums\RemittanceKind;

    $field = fn (string $name) => old($name, $setting->{$name});
    $peso = fn (int $centavos): string => number_format($centavos / 100, 2, '.', '');
@endphp

<x-layouts.app title="Remittance templates" heading="Remittance templates" description="The wording, employer details, signatories and defaults used on each fund's Excel file, printout and voucher. Changes apply to every export from now on." :back="['href' => route('remittances.index', ['kind' => $kind->value]), 'label' => 'Remittances']">
    <nav class="mb-4 flex flex-wrap gap-2" aria-label="Fund">
        @foreach ($kinds as $option)
            <a @class(['btn-sm', 'btn-primary' => $option === $kind, 'btn-secondary' => $option !== $kind]) href="{{ route('remittances.settings', ['kind' => $option->value]) }}">{{ $option->label() }}</a>
        @endforeach
    </nav>

    @if ($errors->has('form'))
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first('form') }}</div>
    @endif

    <form method="post" action="{{ route('remittances.settings.update', $kind->value) }}" class="space-y-6" data-dirty-guard>
        @csrf
        @method('PUT')

        <section class="card grid gap-4 p-5 sm:grid-cols-2">
            <h2 class="text-sm font-semibold sm:col-span-2">{{ $kind->label() }} sheet</h2>
            <div class="sm:col-span-2">
                <label class="label" for="title">Title</label>
                <input class="input" id="title" name="title" maxlength="160" value="{{ $field('title') }}" />
            </div>
            <div class="sm:col-span-2">
                <label class="label" for="letterhead_lines">Letterhead lines</label>
                <textarea class="input" id="letterhead_lines" name="letterhead_lines" rows="3" maxlength="600" placeholder="Leave blank to use the company name from Settings">{{ $field('letterhead_lines') }}</textarea>
            </div>
            @if ($kind === RemittanceKind::Hdmf)
                @foreach ([['employer_id', 'Employer ID', 40], ['employer_name', 'Employer name', 160], ['employer_address', 'Employer address', 255], ['employer_contact', 'Contact details', 120], ['form_code', 'Form code', 40], ['membership_program', 'Membership program', 60]] as [$name, $label, $max])
                    <div>
                        <label class="label" for="{{ $name }}">{{ $label }}</label>
                        <input class="input" id="{{ $name }}" name="{{ $name }}" maxlength="{{ $max }}" value="{{ $field($name) }}" />
                    </div>
                @endforeach
            @endif
        </section>

        <section class="card grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
            <h2 class="text-sm font-semibold sm:col-span-2 lg:col-span-4">Defaults for new people (pesos)</h2>
            <div>
                <label class="label" for="default_amount">{{ $kind->amountLabel() }}</label>
                <input class="input text-right tabular" id="default_amount" name="default_amount" inputmode="decimal" value="{{ old('default_amount', $peso($setting->default_amount_centavos)) }}" />
            </div>
            @if ($kind === RemittanceKind::Hdmf)
                <div>
                    <label class="label" for="default_employer_share">Employer share</label>
                    <input class="input text-right tabular" id="default_employer_share" name="default_employer_share" inputmode="decimal" value="{{ old('default_employer_share', $peso($setting->default_employer_share_centavos)) }}" />
                </div>
                <div>
                    <label class="label" for="default_compensation">Monthly compensation</label>
                    <input class="input text-right tabular" id="default_compensation" name="default_compensation" inputmode="decimal" value="{{ old('default_compensation', $peso($setting->default_compensation_centavos)) }}" />
                </div>
            @endif
            @if ($kind === RemittanceKind::Cmpeai)
                <div>
                    <label class="label" for="service_charge_percent">Service charge (%)</label>
                    <input class="input text-right tabular" id="service_charge_percent" name="service_charge_percent" inputmode="decimal" value="{{ old('service_charge_percent', rtrim(rtrim(number_format($setting->service_charge_basis_points / 100, 2, '.', ''), '0'), '.')) }}" />
                </div>
            @endif
        </section>

        <section class="card grid gap-4 p-5 sm:grid-cols-2">
            <h2 class="text-sm font-semibold sm:col-span-2">Signatories</h2>
            @foreach ([['prepared_by_name', 'Prepared by (name)'], ['prepared_by_title', 'Prepared by (position)'], ['noted_by_name', 'Noted by (name)'], ['noted_by_title', 'Noted by (position)']] as [$name, $label])
                <div>
                    <label class="label" for="{{ $name }}">{{ $label }}</label>
                    <input class="input" id="{{ $name }}" name="{{ $name }}" maxlength="120" value="{{ $field($name) }}" />
                </div>
            @endforeach
            @if ($kind === RemittanceKind::Hdmf)
                <div class="sm:col-span-2">
                    <label class="label" for="certification_text">Certification text</label>
                    <textarea class="input" id="certification_text" name="certification_text" rows="3" maxlength="1000">{{ $field('certification_text') }}</textarea>
                </div>
            @endif
        </section>

        <section class="card grid gap-4 p-5 sm:grid-cols-2">
            <h2 class="text-sm font-semibold sm:col-span-2">Voucher for {{ $kind->label() }}</h2>
            <div>
                <label class="label" for="voucher_payee">Payee</label>
                <input class="input" id="voucher_payee" name="voucher_payee" maxlength="160" value="{{ $field('voucher_payee') }}" />
            </div>
            <div>
                <label class="label" for="voucher_address">Payee address</label>
                <input class="input" id="voucher_address" name="voucher_address" maxlength="255" value="{{ $field('voucher_address') }}" />
            </div>
            <div class="sm:col-span-2">
                <label class="label" for="voucher_particulars">Particulars</label>
                <textarea class="input" id="voucher_particulars" name="voucher_particulars" rows="3" maxlength="1000">{{ $field('voucher_particulars') }}</textarea>
                <p class="mt-1 text-xs text-muted">Use <code>{period}</code> for the month and <code>{amount}</code> for the amount in words and figures.</p>
            </div>
        </section>

        <button class="btn-primary"><x-icon name="check" /> Save {{ $kind->label() }} template</button>
    </form>

    <form method="post" action="{{ route('remittances.settings.voucher') }}" class="card mt-8 grid gap-4 p-5 sm:grid-cols-2" data-dirty-guard>
        @csrf
        @method('PUT')
        <input type="hidden" name="kind" value="{{ $kind->value }}" />
        <h2 class="text-sm font-semibold sm:col-span-2">Voucher officials (all funds)</h2>
        @foreach ([['mode_of_payment', 'Default mode of payment'], ['fund_cluster', 'Fund cluster'], ['responsibility_center', 'Responsibility center'], ['supervisor_name', 'Certifying supervisor (name)'], ['supervisor_title', 'Certifying supervisor (position)'], ['accountant_name', 'Accounting head (name)'], ['accountant_title', 'Accounting head (position)'], ['approver_name', 'Approving officer (name)'], ['approver_title', 'Approving officer (position)']] as [$name, $label])
            <div>
                <label class="label" for="v_{{ $name }}">{{ $label }}</label>
                <input class="input" id="v_{{ $name }}" name="{{ $name }}" maxlength="120" value="{{ $voucher->{$name} }}" />
            </div>
        @endforeach
        <div class="sm:col-span-2"><button class="btn-primary"><x-icon name="check" /> Save voucher officials</button></div>
    </form>
</x-layouts.app>
