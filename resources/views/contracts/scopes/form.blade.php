<x-layouts.app :title="$scope->exists ? $scope->name : 'New scope of service'" :heading="$scope->exists ? 'Edit scope of service' : 'New scope of service'" :back="['href' => route('contracts.scopes.index'), 'label' => 'Scopes of service']">
    @if ($scope->exists)
        <x-slot:actions>
            <form method="post" action="{{ route('contracts.scopes.destroy', $scope) }}" data-confirm="Delete “{{ $scope->name }}”? Issued contracts keep their text.">
                @csrf
                @method('DELETE')
                <button class="btn-danger"><x-icon name="trash" /> Delete</button>
            </form>
        </x-slot:actions>
    @endif

    <form method="post" action="{{ $scope->exists ? route('contracts.scopes.update', $scope) : route('contracts.scopes.store') }}" class="card max-w-4xl space-y-4 p-5" data-dirty-guard>
        @csrf
        @if ($scope->exists)
            @method('PUT')
        @endif
        <div>
            <label class="label" for="name">Name</label>
            <input class="input" id="name" name="name" required maxlength="120" value="{{ old('name', $scope->name) }}" placeholder="e.g. Letter Carrier" />
            @error('name')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label" for="position">Position</label>
            <input class="input" id="position" name="position" maxlength="150" value="{{ old('position', $scope->position) }}" />
            <p class="field-help">Workers with this position get this scope by default when issuing.</p>
        </div>
        <div>
            <label class="label" for="office">Office (optional)</label>
            <input class="input" id="office" name="office" maxlength="160" value="{{ old('office', $scope->office) }}" />
        </div>
        <div>
            <label class="label" for="place_of_service">Supervisor (optional)</label>
            <input class="input" id="place_of_service" name="place_of_service" maxlength="160" value="{{ old('place_of_service', $scope->place_of_service) }}" placeholder="e.g. Postmaster of Sample Post Office" />
            <p class="field-help">Fills in “under the direct control and supervision of the …”. Leave Office and Supervisor blank where they differ per person.</p>
        </div>
        <div>
            <label class="label" for="items">Duties, one per line</label>
            <textarea class="input" id="items" name="items" rows="14" required maxlength="6000">{{ old('items', implode("\n", $scope->items ?? [])) }}</textarea>
            <p class="field-help">Numbered 6.1, 6.2 and so on in the contract. Blank lines are ignored.</p>
            @error('items')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="active" value="1" @checked(old('active', $scope->active)) class="size-4 accent-[var(--primary)]" /> Active (offered when issuing)</label>
        <div class="flex gap-3">
            <button class="btn-primary"><x-icon name="check" /> Save</button>
            <a class="btn-secondary" href="{{ route('contracts.scopes.index') }}">Cancel</a>
        </div>
    </form>
</x-layouts.app>
