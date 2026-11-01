@php
    use App\Support\CertificateBlocks;
    use App\Support\ContractBlocks;

    $selectedFont = old('font', $template->font);
    $selectedMargin = old('margin', $template->margin);
    $selectedPaper = old('paper_size', $template->paper_size);
    $active = session()->hasOldInput() ? (bool) old('active') : $template->active;
@endphp

<x-layouts.app :title="'Edit template — '.$template->name" heading="Edit contract template" :back="['href' => route('contracts.templates.index'), 'label' => 'Contract templates']">
    @if ($errors->any())
        <div class="mb-4 flex items-start gap-3 rounded-xl border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">
            <x-icon name="alert" class="mt-0.5 size-4 shrink-0" />
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <script type="application/json" id="editor-data">@json($editor)</script>

    <form method="post" action="{{ route('contracts.templates.update', $template) }}" id="tpl-form" data-dirty-guard>
        @csrf
        @method('PUT')
        <input type="hidden" name="blocks" id="blocks-input" />
        <section class="card mb-4 p-4 sm:p-5">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_8rem_minmax(0,2fr)]">
                <div>
                    <label class="label" for="name">Template name</label>
                    <input class="input" id="name" name="name" required maxlength="120" value="{{ old('name', $template->name) }}" />
                </div>
                <div>
                    <label class="label" for="prefix">No. prefix</label>
                    <input class="input uppercase" id="prefix" name="prefix" required maxlength="8" pattern="[A-Za-z0-9]{1,8}" value="{{ old('prefix', $template->prefix) }}" title="1–8 letters or digits" />
                </div>
                <div>
                    <label class="label" for="description">Scope of service</label>
                    <input class="input" id="description" name="description" maxlength="400" value="{{ old('description', $template->description) }}" />
                </div>
            </div>
            <div class="mt-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="label" for="paper_size">Paper</label>
                    <select class="input" id="paper_size" name="paper_size" data-page-opt>
                        @foreach (CertificateBlocks::PAPER_SIZES as $paper)
                            <option @selected($paper === $selectedPaper)>{{ $paper }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="font">Font</label>
                    <select class="input" id="font" name="font" data-page-opt>
                        <option value="serif" @selected($selectedFont === 'serif')>Serif (Times)</option>
                        <option value="sans" @selected($selectedFont === 'sans')>Sans-serif</option>
                    </select>
                </div>
                <div>
                    <label class="label" for="margin">Margins</label>
                    <select class="input" id="margin" name="margin" data-page-opt>
                        @foreach (CertificateBlocks::MARGINS as $margin)
                            <option value="{{ $margin }}" @selected($margin === $selectedMargin)>{{ ucfirst($margin) }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="mb-2 flex items-center gap-2 text-sm"><input type="checkbox" name="active" value="1" @checked($active) class="size-4 accent-[var(--primary)]" /> Active</label>
                <div class="ml-auto flex items-center gap-2">
                    @if ($issued > 0)
                        <span class="text-xs text-muted">{{ $issued }} issued — they keep their original text</span>
                    @endif
                    <button class="btn-primary"><x-icon name="check" /> Save template</button>
                </div>
            </div>
        </section>
    </form>

    <div class="grid gap-4 xl:grid-cols-[13rem_minmax(0,1fr)_20rem]">
        <section class="card min-w-0 p-3 xl:sticky xl:top-[calc(var(--topbar-h)+1rem)] xl:self-start" aria-labelledby="palette-title">
            <h2 id="palette-title" class="eyebrow px-1 pb-2 pt-1">Blocks</h2>
            <p class="px-1 pb-2 text-xs text-muted">Drag onto the page, or click to add below the selected block.</p>
            <ul class="flex gap-2 overflow-x-auto pb-1 xl:flex-col xl:overflow-visible">
                @foreach (CertificateBlocks::CONTRACT_BLOCK_TYPES as $type)
                    <li class="shrink-0">
                        <button type="button" class="ed-palette flex w-full items-center gap-2.5 rounded-lg border border-line bg-surface px-3 py-2 text-left text-sm transition-colors hover:border-line-strong hover:bg-surface-2" data-palette-type="{{ $type['type'] }}" title="{{ $type['description'] }}">
                            <x-icon :name="$type['icon']" class="size-4 shrink-0 text-muted" />
                            <span class="whitespace-nowrap">{{ $type['label'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="min-w-0" aria-labelledby="page-title">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 id="page-title" class="text-sm font-semibold">Page</h2>
                <div class="flex items-center gap-1 rounded-lg border border-line bg-surface p-0.5 text-xs" role="radiogroup" aria-label="Show">
                    <button type="button" class="ed-mode rounded-md px-2.5 py-1" data-mode="sample" role="radio" aria-checked="true">Sample data</button>
                    <button type="button" class="ed-mode rounded-md px-2.5 py-1" data-mode="placeholders" role="radio" aria-checked="false">Placeholders</button>
                </div>
            </div>
            <div class="rounded-xl border border-line bg-surface-2 p-3 sm:p-6" id="ed-box">
                <div id="ed-scale" class="mx-auto">
                    <div id="ed-paper" class="relative bg-white text-[#111] shadow-elev-2" role="list" aria-label="Contract blocks"></div>
                </div>
            </div>
            <p class="mt-2 text-xs text-muted" id="ed-warning" aria-live="polite"></p>
            <p class="sr-only" id="ed-announce" aria-live="polite"></p>
        </section>

        <section class="card min-w-0 p-4 xl:sticky xl:top-[calc(var(--topbar-h)+1rem)] xl:self-start" aria-labelledby="inspector-title">
            <h2 id="inspector-title" class="text-sm font-semibold">Block settings</h2>
            <div id="ed-inspector" class="mt-3"></div>
            <details class="mt-4 border-t border-line pt-3" open>
                <summary class="cursor-pointer text-xs font-semibold text-muted">Placeholders</summary>
                <p class="mt-2 text-xs text-muted">Click to insert into the text you are editing. Use **double asterisks** for bold.</p>
                <div class="mt-2 flex flex-wrap gap-1.5" id="ed-chips">
                    @foreach (ContractBlocks::fields() as $name => $field)
                        <button type="button" class="ed-chip rounded-md border border-line bg-surface-2 px-1.5 py-0.5 font-mono text-[11px] text-fg-2 hover:border-primary-ink hover:text-primary-ink" data-ph="{{ $name }}" title="{{ $field['label'] }}">{{ str_repeat('{', 2).$name.str_repeat('}', 2) }}</button>
                    @endforeach
                </div>
            </details>
        </section>
    </div>
</x-layouts.app>
