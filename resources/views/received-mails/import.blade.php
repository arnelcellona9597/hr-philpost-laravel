@php
    use App\Support\HrDate;

    $errorsFound = $plan['errors'];
    $new = array_sum(array_column($plan['sheets'], 'new'));
    $existing = array_sum(array_column($plan['sheets'], 'existing'));
    $canCommit = $errorsFound === [] && $new > 0;
@endphp

<x-layouts.app title="Import received mails" :heading="'Import: '.$fileName" :back="['href' => route('received-mails.index'), 'label' => 'Received mails']">
    <div @class(['mb-6 rounded-xl border px-5 py-4', 'border-danger bg-danger-soft' => $errorsFound !== [], 'border-line bg-success-soft' => $errorsFound === []])>
        @if ($errorsFound !== [])
            <p class="font-medium text-danger"><x-icon name="alert" class="mr-1 inline size-4" /> {{ count($errorsFound) }} problem{{ count($errorsFound) === 1 ? '' : 's' }} found. Nothing has been imported. Fix them in Excel and upload again.</p>
        @elseif ($new > 0)
            <p class="font-medium text-success"><x-icon name="check" class="mr-1 inline size-4" /> {{ number_format($new) }} new piece{{ $new === 1 ? '' : 's' }} of mail will be logged{{ $existing ? '; '.number_format($existing).' already in the log will be skipped' : '' }}.</p>
        @else
            <p class="font-medium text-fg">Nothing to import: everything in this file is already logged.</p>
        @endif
    </div>

    @if ($plan['sheets'] !== [])
        <div class="card overflow-hidden">
            <table class="table">
                <thead><tr><th>Sheet</th><th class="text-right">Rows</th><th class="text-right">New</th><th class="text-right">Already logged</th></tr></thead>
                <tbody>
                    @foreach ($plan['sheets'] as $name => $sheet)
                        <tr><td class="font-medium">{{ $name }}</td><td class="text-right tabular">{{ $sheet['rows'] }}</td><td class="text-right tabular">{{ $sheet['new'] }}</td><td class="text-right tabular text-muted">{{ $sheet['existing'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @foreach ($plan['warnings'] as $warning)
        <p class="mt-2 text-sm text-warning">⚠ {{ $warning }}</p>
    @endforeach

    @if ($errorsFound !== [])
        <section class="card mt-6 overflow-hidden">
            <header class="p-4"><h2 class="text-sm font-semibold">Problems to fix</h2></header>
            <div class="max-h-[32rem] overflow-auto">
                <table class="table">
                    <thead><tr><th>Sheet</th><th class="text-right">Row</th><th>Column</th><th>Problem</th></tr></thead>
                    <tbody>
                        @foreach (array_slice($errorsFound, 0, 300) as $issue)
                            <tr><td>{{ $issue['sheet'] }}</td><td class="text-right tabular">{{ $issue['row'] ?? '' }}</td><td class="font-medium">{{ $issue['column'] ?? '' }}</td><td>{{ $issue['message'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @elseif ($canCommit)
        <section class="card mt-6 overflow-hidden">
            <header class="p-4"><h2 class="text-sm font-semibold">First rows to be logged</h2></header>
            <div class="max-h-96 overflow-auto">
                <table class="table">
                    <thead><tr><th>Date</th><th>Tracking number</th><th>Post office</th><th>Description</th></tr></thead>
                    <tbody>
                        @foreach (array_slice(array_values(array_filter($plan['rows'], fn (array $row): bool => ! $row['exists'])), 0, 50) as $row)
                            <tr><td class="whitespace-nowrap tabular">{{ HrDate::format($row['received_on']) }}</td><td class="font-mono text-xs">{{ $row['tracking_number'] }}</td><td>{{ $row['post_office'] }}</td><td class="text-fg-2">{{ $row['description'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <div class="mt-6 flex flex-wrap gap-3">
        @if ($canCommit)
            <form method="post" action="{{ route('received-mails.import.commit') }}" data-confirm="Log {{ number_format($new) }} piece(s) of mail?">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}" />
                <button class="btn-primary"><x-icon name="check" /> Confirm import</button>
            </form>
        @endif
        <a class="btn-secondary" href="{{ route('received-mails.index') }}">{{ $canCommit ? 'Cancel' : 'Back to the log' }}</a>
    </div>
</x-layouts.app>
