@php
    $maxBytes = \App\Services\MediaStore::AVATAR_MAX_BYTES;
    $problem = $errors->first('avatar') ?: $errors->first('name');
@endphp

<x-layouts.app title="My account" heading="My account" description="Your picture, name and password. Your picture appears in the header and account menu.">
    @if ($problem)
        <div class="mb-4 flex items-start gap-3 rounded-xl border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">
            <x-icon name="alert" class="mt-0.5 size-4 shrink-0" />
            <span>{{ $problem }}</span>
        </div>
    @endif
    <div class="grid max-w-5xl gap-6 lg:grid-cols-2">
        <section class="card p-5 lg:col-span-2" aria-labelledby="picture-title">
            <h2 id="picture-title" class="font-semibold">Profile picture</h2>
            <div class="mt-4 flex flex-col gap-5 sm:flex-row sm:items-center">
                <div class="relative shrink-0 self-start">
                    <span data-avatar-current>
                        <x-avatar :name="$account->name" :image-id="$account->avatar_id" class="size-24 text-2xl ring-4 ring-surface-2" />
                    </span>
                    <img data-avatar-preview alt="" class="hidden size-24 rounded-full object-cover ring-4 ring-primary-soft" />
                </div>
                <div class="min-w-0 flex-1 space-y-3">
                    <form method="post" action="{{ route('profile.avatar') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2" data-avatar-form data-max-bytes="{{ $maxBytes }}">
                        @csrf
                        <label class="btn-secondary cursor-pointer focus-within:ring-3 focus-within:ring-primary-soft">
                            <x-icon name="upload" />
                            <span>{{ $account->avatar_id ? 'Choose a new picture' : 'Choose a picture' }}</span>
                            <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif" class="sr-only" required data-avatar-input />
                        </label>
                        <button class="btn-primary" data-avatar-save disabled>Save picture</button>
                        <span class="text-sm text-muted" data-avatar-name aria-live="polite"></span>
                    </form>
                    <p class="field-help !mt-0">PNG, JPG, WEBP or GIF, up to {{ $maxBytes / 1024 / 1024 }} MB. A square photo of your face works best; it is shown as a circle.</p>
                    @if ($account->avatar_id)
                        <form method="post" action="{{ route('profile.avatar') }}" data-confirm="Remove your profile picture?">
                            @csrf
                            @method('DELETE')
                            <button class="btn-danger btn-sm"><x-icon name="trash" class="size-3.5" /> Remove picture</button>
                        </form>
                    @endif
                </div>
            </div>
        </section>

        <form method="post" action="{{ route('profile.name') }}" class="card space-y-4 p-5">
            @csrf
            @method('PUT')
            <h2 class="font-semibold">Profile</h2>
            <div><label class="label" for="name">Full name</label><input class="input" id="name" name="name" required maxlength="120" value="{{ old('name', $account->name) }}" /></div>
            <div><span class="label">Email</span><p class="text-sm">{{ $account->email }}</p></div>
            <div><span class="label">Role</span><p class="text-sm">{{ $account->role->label() }}</p></div>
            <button class="btn-primary">Save</button>
        </form>

        <form method="post" action="{{ route('profile.password') }}" class="card space-y-4 p-5">
            @csrf
            @method('PUT')
            <h2 class="font-semibold">Change password</h2>
            <div>
                <label class="label" for="current">Current password</label>
                <input class="input" id="current" name="current" type="password" autocomplete="current-password" required @error('current') aria-invalid="true" @enderror />
                @error('current')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="password">New password</label>
                <input class="input" id="password" name="password" type="password" autocomplete="new-password" minlength="{{ \App\Rules\HrPassword::MIN_LENGTH }}" required @error('password') aria-invalid="true" @enderror />
                @error('password')
                    <p class="field-error">{{ $message }}</p>
                @else
                    <p class="field-help">At least {{ \App\Rules\HrPassword::MIN_LENGTH }} characters, with letters and numbers.</p>
                @enderror
            </div>
            <div><label class="label" for="password_confirmation">Confirm new password</label><input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required /></div>
            <button class="btn-primary">Change password</button>
        </form>
    </div>
</x-layouts.app>
