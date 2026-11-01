@php
    use App\Support\HrDate;
    use App\Support\Money;

    $pending = count($impact['pending']);
@endphp

<x-layouts.app :title="'Wage order '.$order->order_number" :heading="$order->order_number" :description="$order->region->value.' · '.$order->sector" :back="['href' => route('wage-orders.index'), 'label' => 'Wage orders']">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('wage-orders.edit', $order) }}"><x-icon name="edit" /> Edit</a>
        <form method="post" action="{{ route('wage-orders.destroy', $order) }}" data-confirm="Delete wage order {{ $order->order_number }}?">
            @csrf
            @method('DELETE')
            <button class="btn-danger"><x-icon name="trash" /> Delete</button>
        </form>
    </x-slot:actions>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Daily minimum" :value="Money::format($order->daily_rate_centavos)" :note="'Effective '.HrDate::format($order->effective_on)" />
        <x-stat-card label="Would be raised" :value="number_format($pending)" tone="primary" note="Daily rate below this, or COS and contractual with no rate" />
        <x-stat-card label="Already applied" :value="number_format($impact['applied'])" tone="success" />
        <x-stat-card label="Not changed" :value="number_format($impact['atOrAbove'] + $impact['monthly'] + $impact['noRate'])" :note="$impact['atOrAbove'].' at or above · '.$impact['monthly'].' monthly · '.$impact['noRate'].' regular with no rate'" />
    </div>

    <section class="card mt-6 p-5">
        <h2 class="text-sm font-semibold">Apply to employees</h2>
        <p class="mt-1 text-sm text-muted">Adds this rate to the pay history of active employees in {{ $order->region->value }}, effective {{ HrDate::format($order->effective_on) }}. Monthly-paid staff and anyone already at or above it are left alone. Applying again only picks up people added since.</p>
        @if ($pending > 0)
            <form method="post" action="{{ route('wage-orders.apply', $order) }}" class="mt-3" data-confirm="Raise {{ $pending }} employee(s) to {{ Money::format($order->daily_rate_centavos) }} a day?">
                @csrf
                <button class="btn-primary"><x-icon name="check" /> Apply to {{ number_format($pending) }} employee{{ $pending === 1 ? '' : 's' }}</button>
            </form>
        @else
            <p class="mt-3 text-sm font-medium">Nobody needs a change.</p>
        @endif
    </section>

    @if ($order->remarks)
        <section class="card mt-6 p-5">
            <h2 class="text-sm font-semibold">Remarks</h2>
            <p class="mt-1 whitespace-pre-line text-sm">{{ $order->remarks }}</p>
        </section>
    @endif
</x-layouts.app>
