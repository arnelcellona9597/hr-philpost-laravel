@php
    use App\Support\EmployeeColumns;
    use App\Support\HrDate;
@endphp

<x-layouts.print :title="'Employees — '.number_format($employees->count()).' in one table'" paper="A4 landscape" :back="$back">
    <x-slot:actions>
        <a class="btn-secondary btn-sm" href="{{ $wordUrl }}">Download Word (.docx)</a>
    </x-slot:actions>

    @if ($total > $max)
        <div class="no-print mx-auto mb-4 max-w-[11.69in] rounded-lg bg-warning-soft px-4 py-3 text-sm text-warning">
            Showing the first {{ number_format($max) }} of {{ number_format($total) }} matching employees. Narrow the filters to get the others.
        </div>
    @endif

    <article class="sheet mx-auto max-w-[11.69in] bg-white p-6 text-[10px] leading-snug text-[#111418]">
        <x-letterhead :site="$site" />
        <h1 class="mt-3 text-center text-lg font-bold">EMPLOYEES</h1>
        <p class="mb-3 text-center text-[11px] text-[#555]">{{ number_format($employees->count()) }} employee{{ $employees->count() === 1 ? '' : 's' }} · {{ HrDate::format(HrDate::today()) }}</p>

        <table class="w-full border-collapse">
            <thead>
                <tr class="bg-[#e8eef8]">
                    <th class="border border-[#999] px-1.5 py-1 text-left">No.</th>
                    @foreach ($columns as $key)
                        <th class="border border-[#999] px-1.5 py-1 text-left">{{ $columnChoices[$key]['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($employees as $employee)
                    <tr class="break-inside-avoid">
                        <td class="border border-[#999] px-1.5 py-1">{{ $loop->iteration }}</td>
                        @foreach ($columns as $key)
                            <td class="border border-[#999] px-1.5 py-1">{{ EmployeeColumns::text($employee, $key, $columnChoices[$key]) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p class="mt-4 text-[9px] text-[#555]">Confidential: contains personal information.</p>
    </article>
</x-layouts.print>
