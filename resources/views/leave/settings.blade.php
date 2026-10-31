@php
    use App\Enums\HolidayType;
    use App\Support\HrDate;

    $overrides = [...($settings->year_overrides ?? []), ['year' => null, 'allowance' => null]];
@endphp

<x-layouts.app title="Leave settings" heading="Wellness leave settings" :back="['href' => route('leave.index'), 'label' => 'Wellness leave']">
    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first() }}</div>
    @endif
    <div class="grid gap-6 xl:grid-cols-2">
        <section class="card p-5">
            <h2 class="font-semibold">Yearly allowance</h2>
            <p class="mt-1 text-sm text-muted">Each employee gets this many wellness leave days per calendar year.</p>
            <form method="post" action="{{ route('leave.allowance') }}" class="mt-4 space-y-4">
                @csrf
                @method('PUT')
                <div class="max-w-48">
                    <label class="label" for="annual_allowance">Days per year (default)</label>
                    <input class="input" id="annual_allowance" name="annual_allowance" type="number" min="0" max="60" required value="{{ old('annual_allowance', $settings->annual_allowance) }}" />
                </div>
                <fieldset>
                    <legend class="label">Overrides for specific years (optional)</legend>
                    <p class="field-help mb-2">Use this when one year has a different allowance. Past years keep their own value when you change the default.</p>
                    <div class="space-y-2">
                        @foreach ($overrides as $override)
                            <div class="flex items-center gap-2">
                                <input class="input max-w-28" name="override_year[]" type="number" min="2000" max="2100" placeholder="Year" value="{{ $override['year'] }}" aria-label="Year" />
                                <input class="input max-w-28" name="override_days[]" type="number" min="0" max="60" placeholder="Days" value="{{ $override['allowance'] }}" aria-label="Days" />
                                <span class="text-xs text-muted">{{ $override['year'] ? 'Clear both boxes to remove' : 'Add a year' }}</span>
                            </div>
                        @endforeach
                    </div>
                </fieldset>
                <button class="btn-primary">Save allowance</button>
            </form>
        </section>

        <section class="card p-5">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Holidays {{ $year }}</h2>
                <div class="flex gap-1">
                    <a class="btn-secondary btn-sm" href="{{ route('leave.settings', ['year' => $year - 1]) }}" aria-label="Previous year">←</a>
                    <a class="btn-secondary btn-sm" href="{{ route('leave.settings', ['year' => $year + 1]) }}" aria-label="Next year">→</a>
                </div>
            </div>
            <p class="mt-1 text-sm text-muted">Non-working days that are skipped when counting leave. Check them against the official proclamation each year.</p>
            <form method="post" action="{{ route('leave.holidays.store') }}" class="mt-4 flex flex-wrap items-end gap-2">
                @csrf
                <div><label class="label" for="h-date">Date</label><input class="input" id="h-date" name="date" type="date" required /></div>
                <div class="min-w-40 flex-1"><label class="label" for="h-name">Name</label><input class="input" id="h-name" name="name" required maxlength="120" /></div>
                <div>
                    <label class="label" for="h-type">Type</label>
                    <select class="input" id="h-type" name="type">
                        @foreach (HolidayType::cases() as $type)
                            <option>{{ $type->value }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn-primary"><x-icon name="plus" /> Add</button>
            </form>
            <ul class="mt-4 divide-y divide-line">
                @forelse ($holidays as $holiday)
                    <li class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span><span class="tabular font-medium">{{ HrDate::format($holiday->date) }}</span> · {{ $holiday->name }} <span class="text-xs text-muted">({{ $holiday->type->value }})</span></span>
                        <form method="post" action="{{ route('leave.holidays.destroy', $holiday) }}" data-confirm="Remove {{ $holiday->name }}?">
                            @csrf
                            @method('DELETE')
                            <button class="btn-ghost btn-sm" aria-label="Remove {{ $holiday->name }}"><x-icon name="x" /></button>
                        </form>
                    </li>
                @empty
                    <li class="py-3 text-sm text-muted">No holidays recorded for {{ $year }}.</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-layouts.app>
