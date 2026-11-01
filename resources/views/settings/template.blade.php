@php
    $selected = old('category', $template?->category->value ?? 'Announcement');
    $token = fn (string $name): string => str_repeat('{', 2).$name.str_repeat('}', 2);
    $fieldError = fn (string $name) => $errors->first($name);
@endphp

<x-layouts.app :title="$template ? $template->name : 'New template'" :heading="$template ? 'Template: '.$template->name : 'New email template'" :back="['href' => route('settings.notifications'), 'label' => 'Email settings']">
    <x-slot:actions>
        @if ($template && ! $template->isSystemTemplate())
            <form method="post" action="{{ route('settings.templates.destroy', $template) }}" data-confirm="Delete this template?">
                @csrf
                @method('DELETE')
                <button class="btn-danger"><x-icon name="trash" /> Delete</button>
            </form>
        @endif
    </x-slot:actions>

    @if ($template?->isSystemTemplate())
        <p class="mb-4 rounded-lg bg-primary-soft px-4 py-3 text-sm text-primary-ink">This template is used for automatic emails ({{ $template->key }}). You can change the wording; it can't be deleted.</p>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <form method="post" action="{{ $template ? route('settings.templates.update', $template) : route('settings.templates.store') }}" class="card space-y-4 p-5 xl:col-span-2" data-dirty-guard>
            @csrf
            @if ($template)
                @method('PUT')
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="name">Name</label>
                    <input class="input" id="name" name="name" required maxlength="120" value="{{ old('name', $template?->name) }}" />
                    @if ($fieldError('name'))
                        <p class="mt-1 text-xs text-danger">{{ $fieldError('name') }}</p>
                    @endif
                </div>
                <div>
                    <label class="label" for="category">Category</label>
                    <select class="input" id="category" name="category">
                        @foreach ($categories as $category)
                            <option @selected($category->value === $selected)>{{ $category->value }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label class="label" for="subject">Subject</label>
                <input class="input" id="subject" name="subject" required maxlength="200" value="{{ old('subject', $template?->subject) }}" />
                @if ($fieldError('subject'))
                    <p class="mt-1 text-xs text-danger">{{ $fieldError('subject') }}</p>
                @endif
            </div>
            <div>
                <label class="label" for="body">Message</label>
                <textarea class="input min-h-72" id="body" name="body" required>{{ old('body', $template?->body) }}</textarea>
                @if ($fieldError('body'))
                    <p class="mt-1 text-xs text-danger">{{ $fieldError('body') }}</p>
                @endif
            </div>
            <button class="btn-primary">Save template</button>
        </form>
        <aside class="card h-fit p-4 text-sm">
            <h2 class="mb-2 font-semibold">Placeholders</h2>
            <ul class="space-y-1">
                @foreach ($placeholders as $name => $description)
                    <li><code class="font-mono text-xs">{{ $token($name) }}</code> <span class="text-muted">— {{ $description }}</span></li>
                @endforeach
            </ul>
        </aside>
    </div>
</x-layouts.app>
