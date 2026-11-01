@php
    use App\Support\HrDate;
    use App\Support\RecordForm;

    $errorsFound = $plan['errors'];
    $changes = array_values(array_filter($plan['plans'], fn (array $row): bool => $row['action'] !== 'unchanged'));
    $canCommit = $errorsFound === [] && $changes !== [];
    $fieldLabel = fn (array $row, string $column): string => collect($row['module']['fields'])->first(fn (array $field): bool => RecordForm::column($field) === $column)['label'] ?? $column;
    $show = function (mixed $value): string {
        if ($value === null || $value === '') {
            return '(blank)';
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return HrDate::format($value);
        }

        return mb_substr((string) $value, 0, 60);
    };
@endphp

<x-layouts.app title="Import preview" :heading="'Import: '.$fileName" :back="['href' => route('data.index'), 'label' => 'Import / Export']">
    @if ($reason)
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ $reason }}</div>
    @endif

    <div @class(['mb-6 rounded-xl border px-5 py-4', 'border-danger bg-danger-soft' => $errorsFound !== [], 'border-line bg-success-soft' => $errorsFound === []])>
        @if ($errorsFound !== [])
            <p class="font-medium text-danger">
                <x-icon name="alert" class="mr-1 inline size-4" />
                {{ count($errorsFound) >= 50 ? '50 or more' : count($errorsFound) }} problem{{ count($errorsFound) === 1 ? '' : 's' }} found. Nothing has been imported. Fix the rows below in Excel and upload again.
            </p>
        @elseif ($changes !== [])
            <p class="font-medium text-success"><x-icon name="check" class="mr-1 inline size-4" /> All rows passed every check. Review the changes, then confirm.</p>
        @else
            <p class="font-medium text-fg">Nothing to import — every row matches what's already saved.</p>
        @endif
    </div>

    @if ($plan['sheets'] !== [])
        <div class="card overflow-hidden">
            <table class="table">
                <thead><tr><th>Sheet</th><th>Imports into</th><th class="text-right">Rows</th><th class="text-right">New</th><th class="text-right">Updated</th><th class="text-right">Unchanged</th><th class="text-right">Problems</th></tr></thead>
                <tbody>
                    @foreach ($plan['sheets'] as $sheet)
                        <tr>
                            <td class="font-medium">{{ $sheet['sheet'] }}</td>
                            <td>{{ $sheet['title'] }}</td>
                            <td class="text-right tabular">{{ $sheet['rows'] }}</td>
                            <td class="text-right tabular">{{ $sheet['create'] }}</td>
                            <td class="text-right tabular">{{ $sheet['update'] }}</td>
                            <td class="text-right tabular text-muted">{{ $sheet['unchanged'] }}</td>
                            <td @class(['text-right tabular', 'font-semibold text-danger' => $sheet['errors'] > 0])>{{ $sheet['errors'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($plan['warnings'] !== [])
        <ul class="mt-3 space-y-1 text-sm text-warning">
            @foreach ($plan['warnings'] as $warning)
                <li>⚠ {{ $warning }}</li>
            @endforeach
        </ul>
    @endif

    @if ($errorsFound !== [])
        <section class="card mt-6 overflow-hidden">
            <header class="p-4"><h2 class="text-sm font-semibold">Problems to fix</h2></header>
            <div class="max-h-[32rem] overflow-auto">
                <table class="table">
                    <thead><tr><th>Sheet</th><th class="text-right">Row</th><th>Column</th><th>Problem</th></tr></thead>
                    <tbody>
                        @foreach (array_slice($errorsFound, 0, 300) as $issue)
                            <tr>
                                <td>{{ $issue['sheet'] }}</td>
                                <td class="text-right tabular">{{ $issue['row'] ?? '' }}</td>
                                <td class="font-medium">{{ $issue['column'] ?? '' }}</td>
                                <td>{{ $issue['message'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($canCommit)
        <section class="card mt-6 overflow-hidden">
            <header class="p-4"><h2 class="text-sm font-semibold">What will change ({{ count($changes) }})</h2></header>
            <div class="max-h-[32rem] overflow-auto">
                <table class="table">
                    <thead><tr><th>Sheet</th><th class="text-right">Row</th><th>Record</th><th>Change</th></tr></thead>
                    <tbody>
                        @foreach (array_slice($changes, 0, 500) as $row)
                            <tr>
                                <td class="text-fg-2">{{ $row['sheet'] }}</td>
                                <td class="text-right tabular">{{ $row['row'] }}</td>
                                <td class="font-medium">{{ $row['label'] }}</td>
                                <td class="text-sm">
                                    @if ($row['action'] === 'create')
                                        <span class="badge-success">New record</span>
                                    @else
                                        <ul class="space-y-0.5">
                                            @foreach ($row['changes'] as $column => $change)
                                                <li><span class="font-medium">{{ $fieldLabel($row, $column) }}</span>: <span class="text-muted line-through">{{ $show($change['from']) }}</span> → {{ $show($change['to']) }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (count($changes) > 500)
                <p class="p-3 text-xs text-muted">Showing the first 500 of {{ count($changes) }} changes.</p>
            @endif
        </section>
    @endif

    <div class="mt-6 flex flex-wrap gap-3">
        @if ($canCommit)
            <form method="post" action="{{ route('data.import.commit') }}" data-confirm="Import {{ count($changes) }} change(s)? This saves everything in one step.">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}" />
                <button class="btn-primary"><x-icon name="check" /> Confirm import</button>
            </form>
        @endif
        <a class="btn-secondary" href="{{ route('data.index') }}">{{ $canCommit ? 'Cancel' : 'Upload another file' }}</a>
    </div>
</x-layouts.app>
