<x-layouts.app title="File wellness leave" :back="['href' => route('leave.index'), 'label' => 'Wellness leave']">
    <div class="grid gap-6 xl:grid-cols-3">
        <div class="xl:col-span-2">
            <x-module-form
                :fields="$fields"
                :values="$values"
                :choices="['employee' => $employees]"
                :cancel-href="route('leave.index')"
                submit-label="Save leave"
                :action="route('leave.store')"
            >
                <x-leave.leave-preview />
            </x-module-form>
        </div>
        <aside class="card h-fit p-5 text-sm text-fg-2">
            <h2 class="mb-2 font-semibold text-fg">How days are counted</h2>
            <ul class="list-disc space-y-1 pl-5">
                <li>Only Monday–Friday count. Weekends and the holidays listed under Allowance &amp; holidays are skipped.</li>
                <li>A filing is blocked if it would go over the employee's yearly allowance or overlap another filing.</li>
                <li>Leave that runs from December into January is charged to both years.</li>
                <li>Disapproved and cancelled filings don't use up days.</li>
            </ul>
        </aside>
    </div>
</x-layouts.app>
