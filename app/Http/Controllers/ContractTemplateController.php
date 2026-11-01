<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\ContractTemplate;
use App\Models\SiteSetting;
use App\Support\CertificateBlocks;
use App\Support\ContractBlocks;
use App\Support\DefaultContractTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ContractTemplateController extends Controller
{
    use RespondsToForms;

    public function index(): View
    {
        Gate::authorize('viewAny', ContractTemplate::class);

        return view('contracts.templates.index', [
            'templates' => ContractTemplate::query()->withCount('contracts')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ContractTemplate::class);

        $name = trim((string) $request->input('name'));

        if ($name === '') {
            return $this->refused('Give the new template a name (for example the kind of worker).');
        }

        if (mb_strlen($name) > 120) {
            return $this->refused('The template name must be at most 120 characters.');
        }

        if (ContractTemplate::query()->where('name', $name)->exists()) {
            return $this->refused('A template with this name already exists.');
        }

        $template = ContractTemplate::query()->create(['name' => $name, 'prefix' => 'COS', 'blocks' => DefaultContractTemplate::blocks()]);

        return $this->flash(redirect()->route('contracts.templates.edit', $template), "Template “{$template->name}” created from the standard contract. Drag blocks to change it, then save.");
    }

    public function edit(ContractTemplate $template): View
    {
        Gate::authorize('update', $template);

        $posted = json_decode((string) old('blocks'), true);
        $site = SiteSetting::current();
        $types = CertificateBlocks::CONTRACT_BLOCK_TYPES;
        $names = array_column($types, 'type');

        return view('contracts.templates.edit', [
            'template' => $template,
            'issued' => $template->contracts()->count(),
            'editor' => [
                'blocks' => is_array($posted) ? $posted : CertificateBlocks::sanitize($template->blocks ?: DefaultContractTemplate::blocks(), ContractBlocks::fields()),
                'companyName' => $site->company_name,
                'logoUrl' => $site->logo_id ? route('files.show', $site->logo_id, false) : null,
                'blockTypes' => $types,
                'newBlocks' => array_combine($names, array_map(CertificateBlocks::newBlock(...), $names)),
                'fields' => ContractBlocks::fields(),
                'margins' => CertificateBlocks::MARGIN_POINTS,
                'spacers' => CertificateBlocks::SPACER_POINTS,
                'maxBlocks' => CertificateBlocks::MAX_BLOCKS,
            ],
        ]);
    }

    public function update(Request $request, ContractTemplate $template): RedirectResponse
    {
        Gate::authorize('update', $template);

        $name = trim((string) $request->input('name'));
        $prefix = mb_strtoupper(trim((string) $request->input('prefix')));
        $description = trim((string) $request->input('description'));
        $errors = [];

        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Give the template a name of at most 120 characters.';
        } elseif (ContractTemplate::query()->where('name', $name)->whereKeyNot($template->id)->exists()) {
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
            $blocks = CertificateBlocks::sanitize((string) $request->input('blocks'), ContractBlocks::fields());
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

        return $this->flash(redirect()->route('contracts.templates.edit', $template), 'Template saved. New contracts will use this layout; issued ones keep theirs.');
    }

    public function duplicate(ContractTemplate $template): RedirectResponse
    {
        Gate::authorize('create', ContractTemplate::class);

        $name = "{$template->name} (copy)";

        for ($number = 2; ContractTemplate::query()->where('name', $name)->exists(); $number++) {
            $name = "{$template->name} (copy {$number})";
        }

        $copy = $template->replicate(['key']);
        $copy->name = $name;
        $copy->sort_order = $template->sort_order + 1;
        $copy->active = true;
        $copy->save();

        return $this->flash(redirect()->route('contracts.templates.edit', $copy), "Copied to “{$copy->name}”.");
    }

    public function toggle(ContractTemplate $template): RedirectResponse
    {
        Gate::authorize('update', $template);

        $template->update(['active' => ! $template->active]);

        return $this->flash(redirect()->route('contracts.templates.index'), "“{$template->name}” is now ".($template->active ? 'active' : 'inactive').'.');
    }

    public function destroy(ContractTemplate $template): RedirectResponse
    {
        Gate::authorize('delete', $template);

        $blocker = $template->deleteBlocker();

        if ($blocker !== null) {
            return $this->flash(redirect()->route('contracts.templates.index'), $blocker, 'error');
        }

        $template->delete();

        return $this->flash(redirect()->route('contracts.templates.index'), 'Template deleted.');
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
