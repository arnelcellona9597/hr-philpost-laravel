<x-layouts.app title="Log mail" heading="Log received mail" :back="['href' => route('received-mails.index'), 'label' => 'Received mails']" description="Add everything that arrived on one day. You can paste rows copied from Excel: Tracking number, Post office, Description.">
    @if ($errors->has('form'))
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first('form') }}</div>
    @endif

    <form method="post" action="{{ route('received-mails.store') }}" class="space-y-6" id="mail-form" data-dirty-guard>
        @csrf
        <section class="card grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label class="label" for="received_on">Date received</label>
                <input class="input" id="received_on" name="received_on" type="date" required value="{{ $date }}" />
            </div>
            <div>
                <label class="label" for="received_by">Received by</label>
                <input class="input" id="received_by" name="received_by" maxlength="120" value="{{ $receivedBy }}" />
            </div>
        </section>

        <section class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table" id="mail-rows">
                    <thead><tr><th class="w-10 text-right">#</th><th class="min-w-48">Tracking number</th><th class="min-w-44">Post office</th><th class="min-w-72">Description</th><th class="min-w-40">Remarks</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($rows as $index => $row)
                            <tr data-row>
                                <td class="text-right text-muted tabular" data-number>{{ $loop->iteration }}</td>
                                <td><input class="input font-mono" name="rows[{{ $index }}][tracking_number]" maxlength="60" value="{{ $row['tracking_number'] ?? '' }}" aria-label="Tracking number" /></td>
                                <td><input class="input" name="rows[{{ $index }}][post_office]" maxlength="120" list="offices" value="{{ $row['post_office'] ?? '' }}" aria-label="Post office" /></td>
                                <td><input class="input" name="rows[{{ $index }}][description]" maxlength="2000" value="{{ $row['description'] ?? '' }}" aria-label="Description" /></td>
                                <td><input class="input" name="rows[{{ $index }}][remarks]" maxlength="1000" value="{{ $row['remarks'] ?? '' }}" aria-label="Remarks" /></td>
                                <td><button type="button" class="btn-ghost btn-sm" data-remove aria-label="Remove row"><x-icon name="x" class="size-3.5" /></button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <datalist id="offices">
                @foreach ($offices as $name)
                    <option value="{{ $name }}"></option>
                @endforeach
            </datalist>
            <div class="flex flex-wrap items-center gap-3 border-t border-line p-4">
                <button type="button" class="btn-secondary btn-sm" id="add-row"><x-icon name="plus" class="size-3.5" /> Add row</button>
                <p class="text-xs text-muted">Empty rows are ignored.</p>
            </div>
        </section>

        <div class="flex flex-wrap gap-3">
            <button class="btn-primary"><x-icon name="check" /> Save mail</button>
            <a class="btn-secondary" href="{{ route('received-mails.index') }}">Cancel</a>
        </div>
    </form>
</x-layouts.app>
