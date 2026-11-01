@php
    use App\Support\HrDate;
    use Illuminate\Support\Carbon;

    $monthLabel = fn (string $value): string => Carbon::parse("{$value}-01")->format('F Y');
    $previousDay = null;
@endphp

<x-layouts.app title="Received Mails" heading="Received mails" description="Log the mail that reaches the office, find it by tracking number, and keep a monthly record.">
    <x-slot:actions>
        @if ($month !== 'all')
            <a class="btn-secondary" href="{{ route('received-mails.print', ['month' => $month]) }}" target="_blank" rel="noopener"><x-icon name="printer" /> Print</a>
        @endif
        <a class="btn-secondary" href="{{ route('received-mails.export', ['month' => $month]) }}"><x-icon name="download" /> Excel</a>
        <a class="btn-primary" href="{{ route('received-mails.create') }}"><x-icon name="plus" /> Log mail</a>
    </x-slot:actions>

    <form method="get" class="card mb-4 flex flex-wrap items-end gap-3 p-4" data-autosubmit>
        <div>
            <label class="label" for="month">Month</label>
            <select class="input" id="month" name="month">
                <option value="all" @selected($month === 'all')>All months</option>
                @foreach ($months as $option)
                    <option value="{{ $option }}" @selected($option === $month)>{{ $monthLabel($option) }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-56 flex-1">
            <label class="label" for="q">Search</label>
            <input class="input" id="q" name="q" type="search" value="{{ $search }}" placeholder="Tracking number, post office or description" />
        </div>
        <div>
            <label class="label" for="post_office">Post office</label>
            <select class="input" id="post_office" name="post_office">
                <option value="">All</option>
                @foreach ($offices as $name)
                    <option @selected($name === $office)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn-secondary"><x-icon name="search" /> Search</button>
        @if ($search !== '' || $office !== '')
            <a class="btn-ghost" href="{{ route('received-mails.index', ['month' => $month]) }}">Clear</a>
        @endif
    </form>

    <div class="card overflow-hidden">
        <header class="flex flex-wrap items-center justify-between gap-2 p-4">
            <h2 class="text-sm font-semibold">{{ $month === 'all' ? 'All months' : $monthLabel($month) }}</h2>
            <p class="text-xs text-muted tabular">{{ number_format($monthTotal) }} piece{{ $monthTotal === 1 ? '' : 's' }} of mail on {{ $daysWithMail }} day{{ $daysWithMail === 1 ? '' : 's' }}</p>
        </header>
        @if ($mails->isEmpty())
            <x-empty-state title="No mail logged" text="Log the mail that arrived today, or import a month from your Excel log.">
                <a class="btn-primary" href="{{ route('received-mails.create') }}">Log mail</a>
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th class="text-right">No.</th><th>Date</th><th>Tracking number</th><th>Post office</th><th>Description</th><th class="hidden lg:table-cell">Remarks</th><th class="hidden lg:table-cell">Received</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($mails as $index => $mail)
                            @php
                                $day = $mail->received_on->toDateString();
                                $newDay = $day !== $previousDay;
                                $previousDay = $day;
                            @endphp
                            <tr @class(['border-t-2 border-line-strong' => $newDay && ! $loop->first])>
                                <td class="text-right text-muted tabular">{{ $mails->firstItem() + $index }}</td>
                                <td class="whitespace-nowrap tabular">{{ $newDay ? HrDate::format($mail->received_on) : '' }}</td>
                                <td class="whitespace-nowrap font-mono text-xs">{{ $mail->tracking_number }}</td>
                                <td>{{ $mail->post_office }}</td>
                                <td class="max-w-md text-fg-2">{{ $mail->description }}</td>
                                <td class="hidden max-w-xs text-fg-2 lg:table-cell">{{ $mail->remarks }}</td>
                                <td class="hidden text-fg-2 lg:table-cell">{{ $mail->received_by }}</td>
                                <td class="text-right"><a class="btn-ghost btn-sm" href="{{ route('received-mails.edit', $mail) }}" aria-label="Edit {{ $mail->tracking_number }}"><x-icon name="edit" class="size-3.5" /></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$mails" />
        @endif
    </div>

    <details class="card mt-6 p-5">
        <summary class="cursor-pointer text-sm font-semibold">Import from your Excel log</summary>
        <p class="mt-2 text-sm text-muted">Upload the workbook you keep now, with one sheet per month. The date can be written once for each day. Mail already logged is skipped, so you can import the same file again safely. You see the result before anything is saved.</p>
        <form method="post" action="{{ route('received-mails.import') }}" enctype="multipart/form-data" class="mt-3 flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="label" for="file">Excel file (.xlsx)</label>
                <input class="input" id="file" name="file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
            </div>
            <button class="btn-secondary"><x-icon name="upload" /> Check file</button>
        </form>
    </details>
</x-layouts.app>
