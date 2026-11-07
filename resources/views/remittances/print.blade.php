@php
    use App\Enums\RemittanceKind;
    use App\Support\HrDate;
    use App\Support\Money;
    use App\Support\Names;

    $kind = $remittance->kind;
    $letterhead = trim((string) $setting->letterhead_lines) !== '' ? trim((string) $setting->letterhead_lines) : mb_strtoupper($site->company_name).($site->tagline ? "\n{$site->tagline}" : '');
    $cell = 'border border-[#999] px-2 py-1';
    $columns = match ($kind) {
        RemittanceKind::Sss => ['Line No.', 'Job Order / Contractual Name', 'Date of Birth', 'SSS Number', 'Amount'],
        RemittanceKind::Hdmf => ['Pag-IBIG ID/RTN', 'Last Name', 'First Name', 'Ext.', 'Middle Name', 'PERCOV', 'Monthly Compensation', 'ER Share', 'EE Share', 'Remarks'],
        RemittanceKind::Cmpeai => ['No.', 'Name', 'Station', 'Total Payment'],
    };
@endphp

<x-layouts.print :title="$remittance->label" paper="A4" :back="route('remittances.show', $remittance)">
    <article class="sheet mx-auto max-w-[11in] bg-white p-6 text-[12px] text-[#111418]">
        <header class="text-center">
            <p>Republic of the Philippines</p>
            <p class="whitespace-pre-line text-sm font-bold">{{ $letterhead }}</p>
            <h1 class="mt-5 text-lg font-bold">{{ mb_strtoupper($setting->title) }}</h1>
            <p>FOR THE MONTH OF {{ mb_strtoupper($remittance->periodLabel()) }}</p>
        </header>
        @if ($kind === RemittanceKind::Hdmf)
            <dl class="mt-4 grid grid-cols-[9rem_1fr] gap-x-3 gap-y-0.5">
                @foreach ([['Employer ID', $setting->employer_id], ['Employer Name', $setting->employer_name], ['Employer Address', $setting->employer_address], ['Contact Details', $setting->employer_contact], ['Form Code', $setting->form_code]] as [$label, $value])
                    <dt class="font-semibold">{{ $label }}</dt><dd>{{ $value }}</dd>
                @endforeach
            </dl>
        @endif
        <table class="mt-4 w-full border-collapse">
            <thead><tr class="bg-[#e8eef8]">@foreach ($columns as $heading)<th class="{{ $cell }} text-left">{{ $heading }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($lines as $index => $line)
                    <tr class="break-inside-avoid">
                        @if ($kind === RemittanceKind::Sss)
                            <td class="{{ $cell }} text-right">{{ $index + 1 }}</td>
                            <td class="{{ $cell }}">{{ $line->full_name }}</td>
                            <td class="{{ $cell }} whitespace-nowrap">{{ HrDate::format($line->birth_date) }}</td>
                            <td class="{{ $cell }} whitespace-nowrap">{{ $line->member_number }}</td>
                            <td class="{{ $cell }} text-right tabular">{{ number_format($line->amount_centavos / 100, 2) }}</td>
                        @elseif ($kind === RemittanceKind::Hdmf)
                            <td class="{{ $cell }}">{{ $line->member_number }}</td>
                            <td class="{{ $cell }}">{{ Names::title($line->last_name) }}</td>
                            <td class="{{ $cell }}">{{ Names::title($line->first_name) }}</td>
                            <td class="{{ $cell }}">{{ $line->extension }}</td>
                            <td class="{{ $cell }}">{{ Names::title($line->middle_name) }}</td>
                            <td class="{{ $cell }}">{{ sprintf('%04d%02d', $remittance->year, $remittance->month) }}</td>
                            <td class="{{ $cell }} text-right tabular">{{ number_format($line->compensation_centavos / 100, 2) }}</td>
                            <td class="{{ $cell }} text-right tabular">{{ number_format($line->employer_share_centavos / 100, 2) }}</td>
                            <td class="{{ $cell }} text-right tabular">{{ number_format($line->amount_centavos / 100, 2) }}</td>
                            <td class="{{ $cell }}">{{ $line->remarks }}</td>
                        @else
                            <td class="{{ $cell }} text-right">{{ $index + 1 }}</td>
                            <td class="{{ $cell }}">{{ $line->full_name }}</td>
                            <td class="{{ $cell }}">{{ $line->station }}</td>
                            <td class="{{ $cell }} text-right tabular">{{ number_format($line->amount_centavos / 100, 2) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="font-bold">
                @if ($kind === RemittanceKind::Sss)
                    <tr><td colspan="4" class="{{ $cell }} text-right">TOTAL</td><td class="{{ $cell }} text-right tabular">{{ number_format($totals['gross'] / 100, 2) }}</td></tr>
                @elseif ($kind === RemittanceKind::Hdmf)
                    <tr><td colspan="6" class="{{ $cell }} text-right">SUBTOTAL</td><td class="{{ $cell }} text-right tabular">{{ number_format($totals['compensation'] / 100, 2) }}</td><td class="{{ $cell }} text-right tabular">{{ number_format($totals['employer'] / 100, 2) }}</td><td class="{{ $cell }} text-right tabular">{{ number_format($totals['gross'] / 100, 2) }}</td><td class="{{ $cell }}"></td></tr>
                @else
                    <tr><td colspan="3" class="{{ $cell }} text-right">GROSS TOTAL</td><td class="{{ $cell }} text-right tabular">{{ number_format($totals['gross'] / 100, 2) }}</td></tr>
                    <tr><td colspan="3" class="{{ $cell }} text-right">LESS: SERVICE CHARGE ({{ rtrim(rtrim(number_format($setting->service_charge_basis_points / 100, 2), '0'), '.') }}%)</td><td class="{{ $cell }} text-right tabular">{{ number_format($totals['serviceCharge'] / 100, 2) }}</td></tr>
                    <tr><td colspan="3" class="{{ $cell }} text-right">NET AMOUNT</td><td class="{{ $cell }} text-right tabular">{{ number_format($totals['net'] / 100, 2) }}</td></tr>
                @endif
            </tfoot>
        </table>
        @if ($kind === RemittanceKind::Hdmf && $setting->certification_text)
            <p class="mt-4">{{ $setting->certification_text }}</p>
        @endif
        <div class="mt-10 grid grid-cols-2 gap-8 break-inside-avoid">
            @foreach ([['Prepared by:', $setting->prepared_by_name, $setting->prepared_by_title], ['Noted by:', $setting->noted_by_name, $setting->noted_by_title]] as [$label, $name, $title])
                <div><p>{{ $label }}</p><p class="mt-8 font-bold">{{ $name ? mb_strtoupper($name) : '' }}</p><p>{{ $title }}</p></div>
            @endforeach
        </div>
    </article>
</x-layouts.print>
