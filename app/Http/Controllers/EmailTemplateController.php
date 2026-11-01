<?php

namespace App\Http\Controllers;

use App\Enums\MessageCategory;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\EmailTemplate;
use App\Support\MergeTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EmailTemplateController extends Controller
{
    use RespondsToForms;

    public function create(): View
    {
        Gate::authorize('create', EmailTemplate::class);

        return view('settings.template', ['template' => null, 'categories' => MessageCategory::cases(), 'placeholders' => MergeTemplate::FIELDS]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', EmailTemplate::class);

        $template = new EmailTemplate;

        return $this->save($request, $template);
    }

    public function edit(EmailTemplate $template): View
    {
        Gate::authorize('update', $template);

        return view('settings.template', [
            'template' => $template,
            'categories' => MessageCategory::cases(),
            'placeholders' => $this->placeholdersFor($template),
        ]);
    }

    public function update(Request $request, EmailTemplate $template): RedirectResponse
    {
        Gate::authorize('update', $template);

        return $this->save($request, $template);
    }

    public function destroy(EmailTemplate $template): RedirectResponse
    {
        Gate::authorize('delete', $template);

        if ($template->isSystemTemplate()) {
            return $this->flash(redirect()->route('settings.templates.edit', $template), "This template is used for automatic emails, so it can't be deleted.", 'error');
        }

        $template->delete();

        return $this->flash(redirect()->route('settings.notifications'), 'Template deleted.');
    }

    private function save(Request $request, EmailTemplate $template): RedirectResponse
    {
        $name = trim((string) $request->input('name'));
        $subject = trim((string) $request->input('subject'));
        $body = trim((string) $request->input('body'));
        $errors = [];

        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Give the template a name of at most 120 characters.';
        } elseif (EmailTemplate::query()->where('name', $name)->when($template->exists, fn ($query) => $query->whereKeyNot($template->id))->exists()) {
            $errors['name'] = 'A template with this name already exists.';
        }

        if ($subject === '' || mb_strlen($subject) > 200) {
            $errors['subject'] = 'Enter a subject of at most 200 characters.';
        }

        if ($body === '') {
            $errors['body'] = 'Write the message.';
        }

        $unknown = MergeTemplate::unknownPlaceholders("{$subject}\n{$body}", array_keys($this->placeholdersFor($template)));

        if ($unknown !== []) {
            $errors['body'] = 'Unknown placeholder(s): '.implode(', ', array_map(fn (string $placeholder): string => "{{{$placeholder}}}", $unknown));
        }

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $template->fill([
            'name' => $name,
            'category' => MessageCategory::tryFrom((string) $request->input('category')) ?? MessageCategory::General,
            'subject' => $subject,
            'body' => $body,
        ])->save();

        return $this->flash(redirect()->route('settings.notifications'), "Template \"{$name}\" saved.");
    }

    /**
     * Automatic emails also know about the event behind them.
     *
     * @return array<string, string>
     */
    private function placeholdersFor(EmailTemplate $template): array
    {
        return $template->isSystemTemplate() ? [...MergeTemplate::FIELDS, ...MergeTemplate::CONTEXT_FIELDS] : MergeTemplate::FIELDS;
    }
}
