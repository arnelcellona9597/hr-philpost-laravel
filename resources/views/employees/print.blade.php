@php
    use App\Support\HrDate;
    use App\Support\Names;
    use Illuminate\Support\Str;

    $sections = collect($fields)->groupBy('section');
    $cell = 'border border-[#999] px-2 py-1 text-left';
@endphp

<x-layouts.print :title="'Employee profile — '.$employee->full_name" paper="A4" :back="route('employees.show', $employee)">
    <article class="sheet mx-auto max-w-[8.27in] bg-white p-6 text-[12px] leading-snug text-[#111418]">
        <x-letterhead :site="$site" />
        <h1 class="mt-3 text-center text-lg font-bold">EMPLOYEE PROFILE</h1>

        <section class="mt-4 flex items-center gap-4 border-y border-[#999] py-3">
            @if ($employee->profile_picture_id)
                <img src="{{ url("/files/{$employee->profile_picture_id}") }}" alt="" class="size-24 object-cover" />
            @else
                <div class="flex size-24 items-center justify-center bg-[#e8eef8] text-2xl font-semibold">{{ Names::initials($employee->first_name.' '.$employee->last_name) }}</div>
            @endif
            <div class="min-w-0">
                <p class="text-base font-bold">{{ $employee->full_name }}</p>
                <p class="font-mono">{{ $employee->employee_id }}</p>
                <p>{{ $employee->position ?: 'No position recorded' }} · {{ $employee->station?->name ?? 'No station' }}</p>
                <p>{{ $employee->employment_status->value }} · {{ $employee->classification->value }}{{ $employee->length_of_service !== '' ? ' · '.$employee->length_of_service.' in service' : '' }}{{ $employee->age !== null ? ' · '.$employee->age.' years old' : '' }}</p>
            </div>
        </section>

        @foreach ($sections as $section => $sectionFields)
            <section class="mt-4">
                <h2 class="mb-2 border-b border-[#999] pb-0.5 text-[13px] font-bold">{{ $section }}</h2>
                <dl class="grid grid-cols-3 gap-x-4 gap-y-1.5">
                    @foreach ($sectionFields as $field)
                        <div @class(['break-inside-avoid', 'col-span-3' => $field['kind'] === 'textarea' || Str::contains($field['name'], 'address')])>
                            <dt class="text-[10px] text-[#555]">{{ $field['label'] }}</dt>
                            <dd @class(['font-mono' => $field['kind'] === 'govId' || $field['name'] === 'bank_account_number'])>{{ $employee->fieldText($field) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach

        <section class="mt-4 break-inside-avoid">
            <h2 class="mb-2 border-b border-[#999] pb-0.5 text-[13px] font-bold">Employment history</h2>
            @if ($employment['rows'] === [])
                <p>No employment history recorded.</p>
            @else
                <table class="w-full border-collapse">
                    <thead><tr class="bg-[#e8eef8]"><th class="{{ $cell }}">No.</th><th class="{{ $cell }}">From</th><th class="{{ $cell }}">To</th><th class="{{ $cell }}">Length</th><th class="{{ $cell }}">Position</th><th class="{{ $cell }}">Station</th><th class="{{ $cell }}">Status</th></tr></thead>
                    <tbody>
                        @foreach ($employment['rows'] as $row)
                            @php($period = $row['period'])
                            <tr class="break-inside-avoid">
                                <td class="{{ $cell }}">{{ $row['number'] }}</td>
                                <td class="{{ $cell }} whitespace-nowrap">{{ HrDate::format($period->hired_on) }}</td>
                                <td class="{{ $cell }} whitespace-nowrap">{{ $period->separated_on ? HrDate::format($period->separated_on) : ($period->separation_type ? 'Date unknown' : 'Present') }}</td>
                                <td class="{{ $cell }}">{{ HrDate::formatSpan($row['length']) ?: 'Less than a day' }}</td>
                                <td class="{{ $cell }}">{{ $period->position }}</td>
                                <td class="{{ $cell }}">{{ $period->station?->name }}</td>
                                <td class="{{ $cell }}">{{ $period->separation_type?->value ?? 'Active' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($employment['total'])
                    <p class="mt-1 font-semibold">Total service: {{ HrDate::formatSpan($employment['total']) }}</p>
                @endif
            @endif
        </section>

        <p class="mt-5 text-[10px] text-[#555]">Printed {{ HrDate::format(HrDate::today()) }}. Confidential: contains personal information.</p>
    </article>
</x-layouts.print>
