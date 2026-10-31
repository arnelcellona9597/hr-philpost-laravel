@php
    use App\Enums\IpcrPeriod;
    use App\Http\Controllers\RequirementDeadlineController;
    use App\Support\Deadlines;
    use App\Support\HrDate;

    $monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $example = function (array $rule) use ($settings, $thisYear): string {
        $due = Deadlines::for([$rule['key'] => $settings[$rule['key']] ?? null], $rule['slug'], $thisYear, $rule['period']);

        return $due ? "{$thisYear} records are due ".HrDate::format($due) : 'Not set — records show Pending / Submitted only';
    };
@endphp

<x-layouts.app
    title="Requirement deadlines"
    heading="Submission deadlines"
    :back="['href' => route('requirements.overview'), 'label' => 'Annual requirements']"
    description="Set when each requirement is due. A record is On time when its Date Submitted is on or before the deadline, otherwise Late; without a submission it becomes Overdue after the deadline."
>
    @if ($errors->any())
        <div class="mb-4 flex items-start gap-3 rounded-xl border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">
            <x-icon name="alert" class="mt-0.5 size-4 shrink-0" />
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <div class="grid gap-6 2xl:grid-cols-2">
        <section class="card min-w-0 p-5" aria-labelledby="usual-title">
            <h2 id="usual-title" class="font-semibold">Usual deadlines</h2>
            <p class="mt-1 text-sm text-muted">Used every year unless a year-specific deadline is set below. Tick “following year” when, for example, the 2026 ITR is due in April 2027.</p>
            <form method="post" action="{{ route('requirements.deadlines.usual') }}" class="mt-4">
                @csrf
                @method('PUT')
                <div class="divide-y divide-line rounded-lg border border-line">
                    <div class="hidden grid-cols-[minmax(0,1.6fr)_10rem_6rem_minmax(0,1fr)] gap-3 bg-surface-2 px-3 py-2.5 md:grid" aria-hidden="true">
                        @foreach (['Requirement', 'Month', 'Day', 'Following year'] as $heading)
                            <span class="eyebrow">{{ $heading }}</span>
                        @endforeach
                    </div>
                    @foreach (Deadlines::RULES as $rule)
                        @php($current = $settings[$rule['key']] ?? [])
                        <fieldset class="grid grid-cols-[minmax(0,1fr)_5.5rem] items-center gap-x-3 gap-y-2 px-3 py-3 md:grid-cols-[minmax(0,1.6fr)_10rem_6rem_minmax(0,1fr)]" data-rule="{{ $rule['key'] }}">
                            <legend class="sr-only">{{ $rule['label'] }}</legend>
                            <div class="col-span-2 min-w-0 md:col-span-1" aria-hidden="true">
                                <p class="text-sm font-medium">{{ $rule['label'] }}</p>
                                <p class="text-xs text-muted">{{ $example($rule) }}</p>
                            </div>
                            <label class="min-w-0">
                                <span class="mb-1 block text-xs text-muted md:sr-only">Month</span>
                                <select class="input" name="{{ $rule['key'] }}-month" aria-label="{{ $rule['label'] }} month">
                                    <option value="">—</option>
                                    @foreach ($monthNames as $index => $month)
                                        <option value="{{ $index + 1 }}" @selected(($current['month'] ?? null) === $index + 1)>{{ $month }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="min-w-0">
                                <span class="mb-1 block text-xs text-muted md:sr-only">Day</span>
                                <input class="input" type="number" min="1" max="31" name="{{ $rule['key'] }}-day" value="{{ $current['day'] ?? '' }}" aria-label="{{ $rule['label'] }} day" />
                            </label>
                            <label class="col-span-2 flex items-center gap-2 text-sm md:col-span-1">
                                <input type="checkbox" class="size-4 accent-[var(--primary)]" name="{{ $rule['key'] }}-next" @checked($current['next_year'] ?? false) /> Following year
                            </label>
                        </fieldset>
                    @endforeach
                </div>
                <button class="btn-primary mt-4"><x-icon name="check" /> Save usual deadlines</button>
            </form>
        </section>

        <section class="card min-w-0 p-5" aria-labelledby="years-title">
            <h2 id="years-title" class="font-semibold">Year-specific deadlines</h2>
            <p class="mt-1 text-sm text-muted">When a year's deadline is different, enter the exact date here. It replaces the usual deadline for that year only. Clear the year and date to remove a row.</p>
            <form method="post" action="{{ route('requirements.deadlines.overrides') }}" class="mt-4">
                @csrf
                @method('PUT')
                <div class="divide-y divide-line rounded-lg border border-line">
                    <div class="hidden grid-cols-[minmax(0,1.6fr)_8rem_6rem_minmax(0,1fr)] gap-3 bg-surface-2 px-3 py-2.5 md:grid" aria-hidden="true">
                        @foreach (['Requirement', 'Rating period (IPCR)', 'Year', 'Deadline'] as $heading)
                            <span class="eyebrow">{{ $heading }}</span>
                        @endforeach
                    </div>
                    @foreach ($rows as $row)
                        <div class="grid grid-cols-2 gap-x-3 gap-y-2 px-3 py-3 md:grid-cols-[minmax(0,1.6fr)_8rem_6rem_minmax(0,1fr)]" data-override>
                            <label class="col-span-2 min-w-0 md:col-span-1">
                                <span class="mb-1 block text-xs text-muted md:sr-only">Requirement</span>
                                <select class="input" name="requirement[]" aria-label="Requirement">
                                    @unless ($row['requirement'])
                                        <option value="">Choose…</option>
                                    @endunless
                                    @foreach (RequirementDeadlineController::NAMES as $slug => $name)
                                        <option value="{{ $slug }}" @selected($slug === $row['requirement'])>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="min-w-0">
                                <span class="mb-1 block text-xs text-muted md:sr-only">Rating period (IPCR)</span>
                                <select class="input" name="period[]" aria-label="Rating period (IPCR only)">
                                    <option value="">—</option>
                                    @foreach (IpcrPeriod::cases() as $period)
                                        <option @selected($period->value === ($row['period'] ?? null))>{{ $period->value }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="min-w-0">
                                <span class="mb-1 block text-xs text-muted md:sr-only">Year</span>
                                <input class="input" type="number" min="2000" max="2100" name="year[]" value="{{ $row['year'] }}" placeholder="{{ $thisYear }}" aria-label="Year" />
                            </label>
                            <label class="col-span-2 min-w-0 md:col-span-1">
                                <span class="mb-1 block text-xs text-muted md:sr-only">Deadline</span>
                                <input class="input" type="date" name="deadline[]" value="{{ $row['deadline'] }}" aria-label="Deadline" />
                            </label>
                        </div>
                    @endforeach
                </div>
                <p class="field-help">The last row is for adding a new deadline. Save to add more.</p>
                <button class="btn-primary mt-3"><x-icon name="check" /> Save year-specific deadlines</button>
            </form>
        </section>
    </div>
</x-layouts.app>
