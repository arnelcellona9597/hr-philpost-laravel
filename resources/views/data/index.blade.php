<x-layouts.app title="Import / Export" heading="Import and export" description="Move HR data in and out as Excel workbooks. Every import is checked row by row and saved all-or-nothing.">
    <a class="mb-4 flex items-center gap-2 rounded-lg bg-surface-2 px-4 py-3 text-sm hover:underline" href="{{ route('data.duplicates') }}"><x-icon name="copy" class="size-4" /> Check for duplicate employees and applicants</a>
    <div class="grid gap-6 xl:grid-cols-2">
        <section class="card p-5">
            <h2 class="font-semibold">Export to Excel</h2>
            <p class="mt-1 text-sm text-muted">Downloads what's in the system now. IDs stay as text (leading zeros kept); dates are real Excel dates.</p>
            <a class="btn-primary mt-4" href="{{ route('data.export', ['module' => 'all']) }}"><x-icon name="download" /> Export ALL data (one workbook)</a>
            <ul class="mt-5 divide-y divide-line">
                @foreach ($modules as ['module' => $module, 'count' => $count, 'yearly' => $yearly])
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                        <span><span class="font-medium">{{ $module['title'] }}</span> <span class="text-muted tabular">· {{ number_format($count) }} record{{ $count === 1 ? '' : 's' }}</span></span>
                        <span class="flex gap-2">
                            @if ($yearly)
                                <a class="btn-ghost btn-sm" href="{{ route('data.export', ['module' => $module['slug'], 'year' => $year]) }}">{{ $year }} only</a>
                            @endif
                            <a class="btn-secondary btn-sm" href="{{ route('data.export', ['module' => $module['slug']]) }}"><x-icon name="download" class="size-3.5" /> All</a>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="card p-5">
            <h2 class="font-semibold">Import from Excel</h2>
            <p class="mt-1 text-sm text-muted">Upload a workbook exported from this system or filled in from the template. You'll see exactly what will change before anything is saved.</p>
            <form method="post" action="{{ route('data.import') }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                @csrf
                <div>
                    <label class="label" for="file">Excel file (.xlsx)</label>
                    <input class="input" id="file" name="file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
                </div>
                <div>
                    <label class="label" for="module">If the file has a single sheet with a different name, import it as</label>
                    <select class="input" id="module" name="module">
                        <option value="">Detect from sheet names</option>
                        @foreach ($modules as ['module' => $module])
                            <option value="{{ $module['slug'] }}">{{ $module['title'] }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn-primary"><x-icon name="upload" /> Check file</button>
            </form>
            <div class="mt-6 rounded-lg bg-surface-2 p-4 text-sm text-fg-2">
                <p class="font-medium text-fg">Templates</p>
                <p class="mt-1">Blank workbooks with the right headers, dropdowns for fixed lists and notes.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a class="btn-secondary btn-sm" href="{{ route('data.template') }}"><x-icon name="download" class="size-3.5" /> All modules</a>
                    @foreach ($modules as ['module' => $module])
                        <a class="btn-ghost btn-sm" href="{{ route('data.template', ['module' => $module['slug']]) }}">{{ $module['sheet'] }}</a>
                    @endforeach
                </div>
            </div>
        </section>
    </div>

    @if ($history->isNotEmpty())
        <section class="card mt-6 overflow-hidden">
            <header class="p-4"><h2 class="text-sm font-semibold">Recent imports</h2></header>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>When</th><th>File</th><th>Sheets</th><th>By</th><th>Result</th></tr></thead>
                    <tbody>
                        @foreach ($history as $job)
                            <tr>
                                <td class="whitespace-nowrap tabular text-fg-2">{{ $job->created_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}</td>
                                <td>{{ $job->file_name }}</td>
                                <td class="text-fg-2">{{ $job->module }}</td>
                                <td>{{ $job->user_name }}</td>
                                <td>
                                    @if ($job->status === \App\Enums\ImportJobStatus::Committed)
                                        <span class="badge-success">{{ $job->created }} new · {{ $job->updated }} updated</span>
                                    @else
                                        <span class="badge-danger" title="{{ $job->error }}">Failed — nothing saved</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-layouts.app>
