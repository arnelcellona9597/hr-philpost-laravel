<?php

use App\Enums\Role;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\User;
use App\Support\CertificateBlocks;
use App\Support\CertificateRenderer;

function templatePayload(array $overrides = []): array
{
    return [
        'name' => 'Travel Abroad',
        'prefix' => 'tvl1',
        'description' => 'For visas',
        'paper_size' => 'Letter',
        'font' => 'sans',
        'margin' => 'wide',
        'active' => '1',
        'blocks' => json_encode([['id' => 'abc', 'type' => 'paragraph', 'text' => 'Hello {{fullName}}', 'align' => 'left', 'size' => 'md', 'indent' => false]]),
        ...$overrides,
    ];
}

beforeEach(function () {
    signIn(User::factory()->create(['role' => Role::HrStaff]));
});

it('creates a template with the starter layout', function () {
    $this->post('/certificates/templates', ['name' => 'New one'])->assertRedirect();

    $template = CertificateTemplate::query()->where('name', 'New one')->sole();

    expect(array_column($template->blocks, 'type'))->toBe(['header', 'divider', 'spacer', 'title', 'spacer', 'paragraph', 'spacer', 'signatory'])
        ->and($template->prefix)->toBe('COE');

    $this->post('/certificates/templates', ['name' => ' '])->assertSessionHasErrors(['form' => 'Give the new template a name (its purpose).']);
    $this->post('/certificates/templates', ['name' => 'New one'])->assertSessionHasErrors(['form' => 'A template with this name already exists.']);
});

it('saves the layout from the editor and uppercases the prefix', function () {
    $template = CertificateTemplate::factory()->create();

    $this->put("/certificates/templates/{$template->id}", templatePayload())->assertSessionHasNoErrors();

    $template->refresh();

    expect($template->prefix)->toBe('TVL1')
        ->and($template->paper_size)->toBe('Letter')
        ->and($template->font)->toBe('sans')
        ->and($template->margin)->toBe('wide')
        ->and($template->blocks[0]['text'])->toBe('Hello {{fullName}}');
});

it('refuses bad layouts and prefixes', function (array $overrides, string $field, string $message) {
    $template = CertificateTemplate::factory()->create(['name' => 'Original']);

    $this->put("/certificates/templates/{$template->id}", templatePayload($overrides))->assertSessionHasErrors([$field => $message]);

    expect($template->fresh()->name)->toBe('Original');
})->with([
    'unknown placeholder' => [['blocks' => '[{"type":"paragraph","text":"{{salary}}"}]'], 'form', 'Unknown placeholder(s): {{salary}}'],
    'unknown block' => [['blocks' => '[{"type":"video"}]'], 'form', 'The template contains an unknown block.'],
    'empty' => [['blocks' => '[]'], 'form', 'Add at least one block to the template.'],
    'not json' => [['blocks' => 'nope'], 'form', 'The template layout is not valid.'],
    'prefix' => [['prefix' => 'TOO-LONG-1'], 'prefix', 'Use 1–8 capital letters or digits.'],
    'name' => [['name' => ''], 'name', 'Give the template a name of at most 120 characters.'],
]);

it('keeps the posted layout when a save is refused', function () {
    $template = CertificateTemplate::factory()->create(['blocks' => [['id' => 'x', 'type' => 'divider']]]);

    $this->from("/certificates/templates/{$template->id}")
        ->followingRedirects()
        ->put("/certificates/templates/{$template->id}", templatePayload(['blocks' => '[{"id":"keep","type":"paragraph","text":"{{salary}}","align":"left","size":"md","indent":false}]']))
        ->assertOk()
        ->assertSee('Unknown placeholder(s)')
        ->assertSee('keep');
});

it('duplicates a template without its system key', function () {
    $source = CertificateTemplate::query()->where('key', 'coe-active')->sole();

    $this->post("/certificates/templates/{$source->id}/duplicate")->assertRedirect();
    $this->post("/certificates/templates/{$source->id}/duplicate")->assertRedirect();

    $copies = CertificateTemplate::query()->where('name', 'like', "{$source->name} (copy%")->orderBy('id')->get();

    expect($copies->pluck('name')->all())->toBe(["{$source->name} (copy)", "{$source->name} (copy 2)"])
        ->and($copies->first()->key)->toBeNull()
        ->and($copies->first()->blocks)->toEqual($source->blocks);
});

it('toggles a template and keeps those with certificates', function () {
    $template = CertificateTemplate::factory()->create(['active' => true]);

    $this->post("/certificates/templates/{$template->id}/toggle");
    expect($template->fresh()->active)->toBeFalse();

    Certificate::factory()->create(['template_id' => $template->id]);
    $this->delete("/certificates/templates/{$template->id}");
    expect($template->fresh())->not->toBeNull();

    $unused = CertificateTemplate::factory()->create();
    $this->delete("/certificates/templates/{$unused->id}");
    expect($unused->fresh())->toBeNull();
});

it('shows the template list and the editor', function () {
    $template = CertificateTemplate::query()->where('key', 'coe-active')->sole();

    $this->get('/certificates/templates')->assertOk()->assertSee('COE-YYYY-0001');
    $this->get("/certificates/templates/{$template->id}")->assertOk()->assertSee('editor-data', false)->assertSee('{{fullName}}', false);
});

it('escapes template text when rendering and sanitizes block values', function () {
    $blocks = CertificateBlocks::sanitize([
        ['type' => 'paragraph', 'text' => "<img src=x> **b<i>**\nline", 'align' => 'evil', 'size' => 'huge', 'indent' => 'true'],
        ['id' => 'BAD ID', 'type' => 'details', 'fields' => ['fullName', 'fullName', 'nope']],
        ['type' => 'signatory', 'signers' => array_fill(0, 5, ['name' => 'A'])],
    ]);

    $html = CertificateRenderer::html($blocks, ['companyName' => 'X', 'font' => 'serif', 'values' => CertificateBlocks::sampleValues()]);

    expect($blocks[0]['align'])->toBe('justify')
        ->and($blocks[0]['size'])->toBe('md')
        ->and($blocks[0]['indent'])->toBeTrue()
        ->and($blocks[1]['id'])->toMatch('/^[a-z0-9]{1,16}$/')
        ->and($blocks[1]['fields'])->toBe(['fullName'])
        ->and($blocks[2]['signers'])->toHaveCount(4)
        ->and($html)->toContain('&lt;img src=x&gt;')
        ->toContain('<strong>b&lt;i&gt;</strong><br>line')
        ->not->toContain('<img');
});
