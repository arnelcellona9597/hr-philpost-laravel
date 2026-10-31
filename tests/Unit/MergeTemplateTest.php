<?php

use App\Support\MergeTemplate;

it('finds each placeholder once', function () {
    expect(MergeTemplate::placeholders('{{ firstName }} {{firstName}} {{lastName}}'))->toBe(['firstName', 'lastName']);
});

it('reports unknown placeholders', function () {
    expect(MergeTemplate::unknownPlaceholders('{{firstName}} {{salary}}', ['firstName']))->toBe(['salary']);
});

it('renders values and blanks out missing ones', function () {
    expect(MergeTemplate::render('Hi {{firstName}}{{lastName}}! {{count}}', ['firstName' => 'Ana', 'lastName' => null, 'count' => 0]))->toBe('Hi Ana! 0');
});

it('leaves malformed placeholders alone', function () {
    expect(MergeTemplate::render('{{1bad}} {single}', ['1bad' => 'x']))->toBe('{{1bad}} {single}');
});

it('escapes HTML, keeps line breaks and links URLs', function () {
    $html = MergeTemplate::textToHtml("<b>Hi</b>\nline\n\nSee https://example.test/a");

    expect($html)->toContain('&lt;b&gt;Hi&lt;/b&gt;<br>line')
        ->and($html)->toContain('<a href="https://example.test/a">https://example.test/a</a>')
        ->and(substr_count($html, '<p '))->toBe(2);
});
