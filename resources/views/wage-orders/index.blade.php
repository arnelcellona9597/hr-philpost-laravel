@php
    use App\Support\HrDate;
    use App\Support\Money;
@endphp

<x-layouts.app title="Wage Orders" heading="Wage orders" description="The general minimum wage of each region. Add a wage order when one is issued, then apply it to raise the employees it covers.">
    <x-slot:actions>
        <a class="btn-secondary" href="{{ route('data.export', ['module' => 'wage-orders']) }}"><x-icon name="download" /> Excel</a>
        <a class="btn-primary" href="{{ route('wage-orders.create') }}"><x-icon name="plus" /> New wage order</a>
    </x-slot:actions>

    <section class="card overflow-hidden" aria-labelledby="in-force">
        <header class="p-4">
            <h2 id="in-force" class="text-sm font-semibold">Minimum wage in force, by region</h2>
            <p class="text-xs text-muted">As of {{ HrDate::format($today) }}. Where a region has several sectors, the highest rate is shown.</p>
        </header>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Region</th><th class="text-right">Daily minimum</th><th>Wage order</th><th>Sector</th><th>Effective</th></tr></thead>
                <tbody>
                    @foreach ($regions as $option)
                        @php($current = $inForce->get($option->value))
                        <tr>
                            <td class="font-medium"><a class="hover:text-primary-ink" href="{{ route('wage-orders.index', ['region' => $option->value]) }}">{{ $option->value }}</a></td>
                            @if ($current)
                                <td class="text-right font-medium tabular">{{ Money::format($current->daily_rate_centavos) }}</td>
                                <td><a class="text-primary-ink hover:underline" href="{{ route('wage-orders.show', $current) }}">{{ $current->display_name }}</a></td>
                                <td class="text-fg-2">{{ $current->sector }}</td>
                                <td class="whitespace-nowrap text-fg-2 tabular">{{ HrDate::format($current->effective_on) }}</td>
                            @else
                                <td class="text-right text-muted">—</td>
                                <td colspan="3" class="text-muted">No wage order recorded</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="card mt-6 overflow-hidden" aria-labelledby="all-orders">
        <header class="flex flex-wrap items-end justify-between gap-3 p-4">
            <h2 id="all-orders" class="text-sm font-semibold">{{ $region ? "All wage orders, {$region->value}" : 'All wage orders' }}</h2>
            <form method="get" data-autosubmit>
                <label class="label !mb-1 text-xs" for="region">Region</label>
                <select class="input py-1.5" id="region" name="region">
                    <option value="">All regions</option>
                    @foreach ($regions as $option)
                        <option @selected($option === $region)>{{ $option->value }}</option>
                    @endforeach
                </select>
            </form>
        </header>
        @if ($orders->isEmpty())
            <x-empty-state title="No wage orders yet" text="Add the wage orders issued for your regions, or import them from Excel under Import / Export.">
                <a class="btn-primary" href="{{ route('wage-orders.create') }}">New wage order</a>
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Wage order</th><th>Region</th><th>Sector</th><th class="text-right">Daily minimum</th><th>Effective</th><th class="text-right">Employees</th></tr></thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr>
                                <td class="font-medium"><a class="text-primary-ink hover:underline" href="{{ route('wage-orders.show', $order) }}">{{ $order->display_name }}</a></td>
                                <td>{{ $order->region->value }}</td>
                                <td class="text-fg-2">{{ $order->sector }}</td>
                                <td class="text-right tabular">{{ Money::format($order->daily_rate_centavos) }}</td>
                                <td class="whitespace-nowrap text-fg-2 tabular">{{ HrDate::format($order->effective_on) }}</td>
                                <td class="text-right tabular">{{ number_format($order->pay_changes_count) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$orders" />
        @endif
    </section>
</x-layouts.app>
