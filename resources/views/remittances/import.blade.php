@php
    use App\Support\Money;

    $errorsFound = $plan['errors'];
    $months = $plan['months'];
    $people = array_sum(array_map(fn (array $month): int => count($month['lines']), $months));
    $canCommit = $errorsFound === [] && $months !== [];
    $kind = $plan['kind'];
    $periodName = fn (array $month): string => \Illuminate\Support\Carbon::create($month['year'], $month['month'], 1)->format('F Y');
@endphp

<x-layouts.app title="Import remittances" :heading="'Import: '.$fileName" :back="['href' => route('remittances.index', ['kind' => $kind->value]), 'label' => 'Remittances']">
    <div @class(['mb-6 rounded-xl border px-5 py-4', 'border-danger bg-danger-soft' => $errorsFound !== [], 'border-line bg-success-soft' => $errorsFound === []])>
        @if ($errorsFound !== [])
            <p class="font-medium text-danger"><x-icon name="alert" class="mr-1 inline size-4" /> {{ count($errorsFound) }} problem{{ count($errorsFound) === 1 ? '' : 's' }} found. Nothing has been imported. Fix them in Excel and upload again.</p>
        @else
            <p class="font-medium text-success"><x-icon name="check" class="mr-1 inline size-4" /> {{ count($months) }} {{ $kind->label() }} month{{ count($months) === 1 ? '' : 's' }} with {{ number_format($people) }} {{ $people === 1 ? 'person' : 'people' }} will be saved.</p>
        @endif
    </div>

    @if ($months !== [])
        <div class="card overflow-hidden">
            <table class="table">
                <thead><tr><th>Sheet</th><th>Month</th><th class="text-right">People</th><th class="text-right">Total</th><th>What happens</th></tr></thead>
                <tbody>
                    @foreach ($months as $month)
                        <tr>
                            <td class="font-medium">{{ $month['sheet'] }}</td>
                            <td>{{ $periodName($month) }}</td>
                            <td class="text-right tabular">{{ count($month['lines']) }}</td>
                            <td class="text-right tabular">{{ Money::format(array_sum(array_column($month['lines'], 'amount_centavos'))) }}</td>
                            <td class="text-fg-2">{{ $month['finalized'] ? 'Finalized — cannot be replaced' : ($month['exists'] ? 'Replaces the month in the system' : 'New month') }}</td>
                        </tr>
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
                    <thead><tr><th>Sheet</th><th class="text-right">Row</th><th>Problem</th></tr></thead>
                    <tbody>
                        @foreach (array_slice($errorsFound, 0, 300) as $issue)
                            <tr><td>{{ $issue['sheet'] }}</td><td class="text-right tabular">{{ $issue['row'] ?? '' }}</td><td>{{ $issue['message'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <div class="mt-6 flex flex-wrap gap-3">
        @if ($canCommit)
            <form method="post" action="{{ route('remittances.import.commit') }}" data-confirm="Save {{ count($months) }} month(s) of {{ $kind->label() }}?">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}" />
                <input type="hidden" name="kind" value="{{ $kind->value }}" />
                <input type="hidden" name="year" value="{{ $fallbackYear }}" />
                <input type="hidden" name="month" value="{{ $fallbackMonth }}" />
                <button class="btn-primary"><x-icon name="check" /> Confirm import</button>
            </form>
        @endif
        <a class="btn-secondary" href="{{ route('remittances.index', ['kind' => $kind->value]) }}">{{ $canCommit ? 'Cancel' : 'Back to remittances' }}</a>
    </div>
</x-layouts.app>
