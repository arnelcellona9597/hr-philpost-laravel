<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\CertificateTemplate;
use App\Models\SiteSetting;
use App\Support\CertificateBlocks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CertificateTemplateController extends Controller
{
    use RespondsToForms;

    public function index(): View
    {
        Gate::authorize('viewAny', CertificateTemplate::class);

        return view('certificates.templates.index', [
            'templates' => CertificateTemplate::query()->withCount('certificates')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', CertificateTemplate::class);

        $name = trim((string) $request->input('name'));

        if ($name === '') {
            return $this->refused('Give the new template a name (its purpose).');
        }

        if (mb_strlen($name) > 120) {
            return $this->refused('The template name must be at most 120 characters.');
        }

        if (CertificateTemplate::query()->where('name', $name)->exists()) {
            return $this->refused('A template with this name already exists.');
        }

        $template = CertificateTemplate::query()->create(['name' => $name, 'prefix' => 'COE', 'blocks' => CertificateBlocks::starterLayout()]);

        return $this->flash(redirect()->route('certificates.templates.edit', $template), "Template “{$template->name}” created. Drag blocks to build it, then save.");
    }

    public function edit(CertificateTemplate $template): View
    {
        Gate::authorize('update', $template);

        $posted = json_decode((string) old('blocks'), true);
        $site = SiteSetting::current();

        return view('certificates.templates.edit', [
            'template' => $template,
            'issued' => $template->certificates()->count(),
            'editor' => [
                'blocks' => is_array($posted) ? $posted : CertificateBlocks::sanitize($template->blocks ?: CertificateBlocks::starterLayout()),
                'companyName' => $site->company_name,
                'logoUrl' => $site->logo_id ? route('files.show', $site->logo_id, false) : null,
                'blockTypes' => CertificateBlocks::BLOCK_TYPES,
                'newBlocks' => array_combine(
                    array_column(CertificateBlocks::BLOCK_TYPES, 'type'),
                    array_map(CertificateBlocks::newBlock(...), array_column(CertificateBlocks::BLOCK_TYPES, 'type')),
                ),
                'fields' => CertificateBlocks::FIELDS,
                'margins' => CertificateBlocks::MARGIN_POINTS,
                'spacers' => CertificateBlocks::SPACER_POINTS,
                'maxBlocks' => CertificateBlocks::MAX_BLOCKS,
            ],
        ]);
    }

    public function update(Request $request, CertificateTemplate $template): RedirectResponse
    {
        Gate::authorize('update', $template);

        $name = trim((string) $request->input('name'));
        $prefix = mb_strtoupper(trim((string) $request->input('prefix')));
        $description = trim((string) $request->input('description'));
        $errors = [];

        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Give the template a name of at most 120 characters.';
        } elseif (CertificateTemplate::query()->where('name', $name)->whereKeyNot($template->id)->exists()) {
            $errors['name'] = 'A template with this name already exists.';
        }

        if (! preg_match('/^[A-Z0-9]{1,8}$/', $prefix)) {
            $errors['prefix'] = 'Use 1–8 capital letters or digits.';
        }

        if (mb_strlen($description) > 400) {
            $errors['description'] = 'Keep the description under 400 characters.';
        }

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        try {
            $blocks = CertificateBlocks::sanitize((string) $request->input('blocks'));
        } catch (UserFacingException $exception) {
            return $this->refused($exception->getMessage());
        }

        $template->update([
            'name' => $name,
            'description' => $description ?: null,
            'prefix' => $prefix,
            'paper_size' => $this->pick($request, 'paper_size', CertificateBlocks::PAPER_SIZES),
            'font' => $this->pick($request, 'font', CertificateBlocks::FONTS),
            'margin' => $this->pick($request, 'margin', CertificateBlocks::MARGINS, 'normal'),
            'active' => $request->boolean('active'),
            'blocks' => $blocks,
        ]);

        return $this->flash(redirect()->route('certificates.templates.edit', $template), 'Template saved. New certificates will use this layout; issued ones keep theirs.');
    }

    public function duplicate(CertificateTemplate $template): RedirectResponse
    {
        Gate::authorize('create', CertificateTemplate::class);

        $name = "{$template->name} (copy)";

        for ($number = 2; CertificateTemplate::query()->where('name', $name)->exists(); $number++) {
            $name = "{$template->name} (copy {$number})";
        }

        $copy = $template->replicate(['key']);
        $copy->name = $name;
        $copy->sort_order = $template->sort_order + 1;
        $copy->active = true;
        $copy->save();

        return $this->flash(redirect()->route('certificates.templates.edit', $copy), "Copied to “{$copy->name}”.");
    }

    public function toggle(CertificateTemplate $template): RedirectResponse
    {
        Gate::authorize('update', $template);

        $template->update(['active' => ! $template->active]);

        return $this->flash(redirect()->route('certificates.templates.index'), "“{$template->name}” is now ".($template->active ? 'active' : 'inactive').'.');
    }

    public function destroy(CertificateTemplate $template): RedirectResponse
    {
        Gate::authorize('delete', $template);

        $blocker = $template->deleteBlocker();

        if ($blocker !== null) {
            return $this->flash(redirect()->route('certificates.templates.index'), $blocker, 'error');
        }

        $template->delete();

        return $this->flash(redirect()->route('certificates.templates.index'), 'Template deleted.');
    }

    /**
     * @param  list<string>  $allowed
     */
    private function pick(Request $request, string $name, array $allowed, ?string $fallback = null): string
    {
        $value = (string) $request->input($name);

        return in_array($value, $allowed, true) ? $value : ($fallback ?? $allowed[0]);
    }
}
