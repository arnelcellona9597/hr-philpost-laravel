@php
    $passed = $plan['errors'] === [] && $plan['rows'] !== [];
    $actions = array_count_values($plan['actions']);
@endphp

<x-layouts.app title="Payroll import" :heading="'Import into '.$period->name" :back="['href' => route('payroll.show', $period), 'label' => $period->name]">
    <div @class(['mb-4 rounded-xl border px-5 py-4', 'border-line bg-success-soft' => $passed, 'border-danger bg-danger-soft' => ! $passed])>
        @if ($passed)
            <p class="font-medium text-success">All rows passed the checks: {{ $actions['update'] ?? 0 }} payslip(s) will be updated and {{ $actions['create'] ?? 0 }} created.</p>
        @else
            <p class="font-medium text-danger">{{ count($plan['errors']) }} problem(s) found. Nothing was saved — fix them in Excel and upload again.</p>
        @endif
    </div>

    @foreach ($plan['warnings'] as $warning)
        <p class="mb-2 text-sm text-warning">⚠ {{ $warning }}</p>
    @endforeach

    @if ($plan['errors'] !== [])
        <div class="card mb-4 overflow-hidden">
            <table class="table">
                <thead><tr><th class="text-right">Row</th><th>Column</th><th>Problem</th></tr></thead>
                <tbody>
                    @foreach (array_slice($plan['errors'], 0, 200) as $error)
                        <tr><td class="text-right tabular">{{ $error['row'] ?? '' }}</td><td>{{ $error['column'] ?? '' }}</td><td>{{ $error['message'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($passed)
        <form method="post" action="{{ route('payroll.import.commit', $period) }}" data-confirm="Save these payslip amounts?">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}" />
            <button class="btn-primary"><x-icon name="check" /> Confirm import</button>
            <a class="btn-secondary ml-2" href="{{ route('payroll.show', $period) }}">Cancel</a>
        </form>
    @else
        <a class="btn-secondary" href="{{ route('payroll.show', $period) }}">Back to the period</a>
    @endif
</x-layouts.app>
