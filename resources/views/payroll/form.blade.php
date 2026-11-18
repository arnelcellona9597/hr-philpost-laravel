@php
    $title = $period ? "Edit {$period->name}" : 'New payroll period';
    $back = $period ? ['href' => route('payroll.show', $period), 'label' => $period->name] : ['href' => route('payroll.index'), 'label' => 'Payroll'];
    $locked = $period !== null && (! $period->isDraft() || $period->payslips()->exists());
    $value = fn (string $key) => old($key, $values[$key] ?? '');
    $months = collect(range(1, 12))->mapWithKeys(fn (int $month): array => [$month => \Illuminate\Support\Carbon::create(2000, $month, 1)->format('F')]);
@endphp

<x-layouts.app :title="$title" :heading="$title" :back="$back" description="COS payroll is semi-monthly: the 1st half covers the 1st to the 15th, the 2nd half the 16th to the last day of the month. The dates, name and code are set from your choice.">
    <form method="post" action="{{ $period ? route('payroll.update', $period) : route('payroll.store') }}" class="card max-w-3xl space-y-5 p-6" data-dirty-guard>
        @csrf
        @if ($period)
            @method('PUT')
        @endif

        @if ($locked)
            <p class="rounded-lg bg-surface-2 px-4 py-3 text-sm text-muted">The month and half can't change once workers are in the payroll or it has left draft. This period covers {{ $period->titleRange() }}.</p>
        @else
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="label" for="month">Month</label>
                    <select class="input" id="month" name="month" required>
                        @foreach ($months as $number => $name)
                            <option value="{{ $number }}" @selected((int) $value('month') === $number)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('month') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="year">Year</label>
                    <input class="input" id="year" name="year" type="number" min="2000" max="2100" required value="{{ $value('year') }}" />
                    @error('year') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="half">Payroll period</label>
                    <select class="input" id="half" name="half" required>
                        <option value="1" @selected((int) $value('half') === 1)>1st half (1–15)</option>
                        <option value="2" @selected((int) $value('half') === 2)>2nd half (16–end of month)</option>
                    </select>
                    @error('half') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="pay_date">Pay date</label>
                <input class="input" id="pay_date" name="pay_date" type="date" required value="{{ $value('pay_date') }}" />
                @error('pay_date') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="batch">Batch</label>
                <input class="input" id="batch" name="batch" maxlength="20" value="{{ $value('batch') }}" />
                <p class="field-help">Printed under the title, e.g. 7 for “BATCH 7”.</p>
            </div>
        </div>
        <div>
            <label class="label" for="notes">Notes</label>
            <textarea class="input" id="notes" name="notes">{{ $value('notes') }}</textarea>
        </div>
        <div class="flex gap-2">
            <button class="btn-primary">{{ $period ? 'Save changes' : 'Create period' }}</button>
            <a class="btn-secondary" href="{{ $back['href'] }}">Cancel</a>
        </div>
    </form>
</x-layouts.app>
