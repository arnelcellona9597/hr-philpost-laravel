@php
    $templateData = $templates->mapWithKeys(fn ($template) => [$template->id => ['subject' => $template->subject, 'body' => $template->body, 'category' => $template->category->value]]);
    $placeholders = [...$mergeFields, ...$contextFields];
    $token = fn (string $name): string => str_repeat('{', 2).$name.str_repeat('}', 2);
@endphp

<x-layouts.app title="New message" heading="New message" :back="['href' => route('messages.index'), 'label' => 'Messages']">
    @if ($errors->has('form'))
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first('form') }}</div>
    @endif

    <script type="application/json" id="compose-templates">@json($templateData)</script>

    <form method="post" action="{{ route('messages.store') }}" enctype="multipart/form-data" class="grid gap-6 xl:grid-cols-3" id="compose" data-dirty-guard data-confirm="Send this message now?" data-preview-url="{{ route('messages.preview') }}" data-categories="{{ collect($categories)->pluck('value')->implode(',') }}">
        @csrf
        @foreach ($context as $name => $value)
            <input type="hidden" name="ctx_{{ $name }}" value="{{ $value }}" />
        @endforeach
        <div class="space-y-6 xl:col-span-2">
            <fieldset class="card space-y-4 p-5">
                <legend class="px-1 text-sm font-semibold">Recipients</legend>
                <div>
                    <label class="label" for="employees">Specific employees</label>
                    <select class="input" id="employees" name="employees[]" multiple size="7" data-searchable>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee['value'] }}" @selected(in_array($employee['value'], $employeeIds, true))>{{ $employee['label'] }}</option>
                        @endforeach
                    </select>
                    <p class="field-help">Ctrl/⌘-click to choose several. <span data-selected-count></span></p>
                </div>
                <div class="rounded-lg bg-surface-2 p-4">
                    <p class="label">Or a group of employees</p>
                    <label class="mb-3 flex items-center gap-2 text-sm"><input type="checkbox" name="all_active" value="1" class="size-4" /> All active employees</label>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label class="label" for="group_branch">Branch</label>
                            <select class="input" id="group_branch" name="group_branch[]" multiple size="4">
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch['value'] }}">{{ $branch['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <span class="label">Classification</span>
                            @foreach ($classifications as $classification)
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="group_classification[]" value="{{ $classification }}" class="size-4" /> {{ $classification }}</label>
                            @endforeach
                        </div>
                        <div>
                            <span class="label">Employment status</span>
                            @foreach ($statuses as $employmentStatus)
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="group_status[]" value="{{ $employmentStatus }}" class="size-4" /> {{ $employmentStatus }}</label>
                            @endforeach
                        </div>
                    </div>
                    <p class="field-help mt-2">Employees must match every filter you choose (e.g. COS in PENRO Laguna).</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="users">HR users</label>
                        <label class="mb-2 flex items-center gap-2 text-sm"><input type="checkbox" name="all_users" value="1" class="size-4" /> All HR users</label>
                        <select class="input" id="users" name="users[]" multiple size="4">
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}">{{ $user->name }} — {{ $user->email }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="emails">Other email addresses</label>
                        <textarea class="input min-h-28" id="emails" name="emails" placeholder="one@example.com, two@example.com">{{ $emails }}</textarea>
                    </div>
                </div>
            </fieldset>

            <fieldset class="card space-y-4 p-5">
                <legend class="px-1 text-sm font-semibold">Message</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="template">Start from a template</label>
                        <select class="input" id="template">
                            <option value="">— Blank —</option>
                            @foreach ($templates as $template)
                                <option value="{{ $template->id }}" @selected($template->id === $chosen?->id)>{{ $template->category->value }}: {{ $template->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="category">Category</label>
                        <select class="input" id="category" name="category">
                            @foreach ($categories as $option)
                                <option @selected($option->value === $category)>{{ $option->value }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="label" for="subject">Subject</label>
                    <input class="input" id="subject" name="subject" required maxlength="200" value="{{ $subject }}" />
                </div>
                <div>
                    <label class="label" for="body">Message</label>
                    <textarea class="input min-h-64 font-[inherit]" id="body" name="body" required>{{ $body }}</textarea>
                </div>
                <div>
                    <label class="label" for="attachments">Attachments (optional)</label>
                    <input class="input" id="attachments" name="attachments[]" type="file" multiple accept="application/pdf,image/png,image/jpeg,image/webp" />
                    <p class="field-help">PDF or images, up to 5 MB in total.</p>
                </div>
            </fieldset>
            <div class="flex flex-wrap gap-3">
                <button type="button" class="btn-secondary" id="preview-btn"><x-icon name="search" /> Preview</button>
                <button class="btn-primary"><x-icon name="send" /> Send</button>
                <a href="{{ route('messages.index') }}" class="btn-ghost">Cancel</a>
            </div>
        </div>

        <aside class="h-fit space-y-4 xl:sticky xl:top-6">
            <section class="card p-4 text-sm">
                <h2 class="mb-2 font-semibold">Personalise with</h2>
                <p class="mb-2 text-xs text-muted">Click to insert. Each person sees their own details.</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($placeholders as $name => $description)
                        <button type="button" class="badge-neutral cursor-pointer font-mono hover:bg-primary-soft" data-insert="{{ $token($name) }}" title="{{ $description }}">{{ $token($name) }}</button>
                    @endforeach
                </div>
            </section>
            <section class="card p-4 text-sm" id="preview" hidden>
                <h2 class="mb-2 font-semibold">Preview</h2>
                <p id="preview-summary" class="mb-2 text-fg-2"></p>
                <ul id="preview-skipped" class="mb-2 space-y-0.5 text-xs text-warning"></ul>
                <iframe id="preview-frame" title="Email preview" class="h-96 w-full rounded-lg border border-line bg-white" sandbox=""></iframe>
            </section>
        </aside>
    </form>
</x-layouts.app>
