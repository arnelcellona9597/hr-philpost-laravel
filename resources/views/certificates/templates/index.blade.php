<x-layouts.app title="Certificate templates" heading="Certificate templates" :back="['href' => route('certificates.index'), 'label' => 'Certificates']" description="One template per purpose. Open a template to arrange its blocks by drag and drop.">
    @if ($errors->has('form'))
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first('form') }}</div>
    @endif

    <section class="card mb-6 p-5">
        <h2 class="font-semibold">New template</h2>
        <form method="post" action="{{ route('certificates.templates.store') }}" class="mt-3 flex flex-wrap items-end gap-3">
            @csrf
            <div class="min-w-64 flex-1">
                <label class="label" for="new-name">Purpose / template name</label>
                <input class="input" id="new-name" name="name" required maxlength="120" value="{{ old('name') }}" placeholder="e.g. Certificate of Employment – Travel Abroad" />
            </div>
            <button class="btn-primary"><x-icon name="plus" /> Create and edit</button>
        </form>
        <p class="field-help">Tip: to start from an existing layout, use <strong>Duplicate</strong> on a template below.</p>
    </section>

    @if ($templates->isEmpty())
        <div class="card"><x-empty-state title="No templates yet." text="Create one above." /></div>
    @else
        <ul class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
            @foreach ($templates as $template)
                <li class="card flex flex-col p-5">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="font-semibold leading-snug">
                            <a href="{{ route('certificates.templates.edit', $template) }}" class="hover:text-primary-ink hover:underline">{{ $template->name }}</a>
                        </h3>
                        <span class="{{ $template->active ? 'badge-success' : 'badge-neutral' }}">{{ $template->active ? 'Active' : 'Inactive' }}</span>
                    </div>
                    @if ($template->description)
                        <p class="mt-1 text-sm text-muted">{{ $template->description }}</p>
                    @endif
                    <p class="mt-3 text-xs text-muted">{{ $template->prefix }}-YYYY-0001 · {{ $template->paper_size }} · {{ $template->font === 'sans' ? 'Sans-serif' : 'Serif' }} · {{ $template->certificates_count }} issued</p>
                    <div class="mt-4 flex flex-wrap gap-2 border-t border-line pt-4">
                        <a class="btn-primary btn-sm" href="{{ route('certificates.templates.edit', $template) }}"><x-icon name="edit" class="size-3.5" /> Edit layout</a>
                        <form method="post" action="{{ route('certificates.templates.duplicate', $template) }}">@csrf<button class="btn-secondary btn-sm"><x-icon name="copy" class="size-3.5" /> Duplicate</button></form>
                        <form method="post" action="{{ route('certificates.templates.toggle', $template) }}">@csrf<button class="btn-ghost btn-sm">{{ $template->active ? 'Deactivate' : 'Activate' }}</button></form>
                        @if ($template->certificates_count === 0)
                            <form method="post" action="{{ route('certificates.templates.destroy', $template) }}" data-confirm="Delete the template “{{ $template->name }}”?">
                                @csrf
                                @method('DELETE')
                                <button class="btn-ghost btn-sm text-danger"><x-icon name="trash" class="size-3.5" /> Delete</button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.app>
