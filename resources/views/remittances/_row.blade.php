@php
    use App\Enums\RemittanceKind;

    $value = fn (string $field): string => $line === null ? '' : (string) ($line->{$field} ?? '');
    $peso = fn (string $field): string => $line === null ? '' : number_format($line->{$field} / 100, 2, '.', '');
    $cell = fn (string $field, string $label, string $class = '', string $extra = '') => '<td class="!px-1"><input class="input py-1 '.$class.'" data-field="'.$field.'" value="'.e($extra !== '' ? $extra : $value($field)).'" aria-label="'.e($label).'" '.($final ? 'readonly' : '').' /></td>';
@endphp
<tr data-row data-employee="{{ $line?->employee_id }}" data-birth="{{ $line?->birth_date?->toDateString() }}" data-station="{{ $line?->station }}">
    <td class="text-right text-muted tabular" data-number>{{ $number }}</td>
    {!! $cell('last_name', 'Last name', 'min-w-36') !!}
    {!! $cell('first_name', 'First name', 'min-w-32') !!}
    {!! $cell('middle_name', 'Middle name', 'min-w-24') !!}
    {!! $cell('extension', 'Extension', 'w-16') !!}
    @if ($kind === RemittanceKind::Sss)
        {!! $cell('birth_date', 'Date of birth (YYYY-MM-DD)', 'w-32 tabular', (string) $line?->birth_date?->toDateString()) !!}
    @endif
    @if ($kind === RemittanceKind::Cmpeai)
        {!! $cell('station', 'Station', 'min-w-40') !!}
    @else
        {!! $cell('member_number', $kind->memberLabel(), 'min-w-36 font-mono') !!}
    @endif
    @if ($kind === RemittanceKind::Hdmf)
        {!! $cell('compensation', 'Monthly compensation', 'w-28 text-right tabular', $peso('compensation_centavos')) !!}
        {!! $cell('employer_share', 'Employer share', 'w-24 text-right tabular', $peso('employer_share_centavos')) !!}
    @endif
    {!! $cell('amount', $kind->amountLabel(), 'w-28 text-right tabular', $peso('amount_centavos')) !!}
    {!! $cell('remarks', 'Remarks', 'min-w-32') !!}
    <td>@unless ($final)<button type="button" class="btn-ghost btn-sm" data-remove aria-label="Remove row"><x-icon name="x" class="size-3.5" /></button>@endunless</td>
</tr>
