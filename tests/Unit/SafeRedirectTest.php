<?php

use App\Support\SafeRedirect;

it('keeps local paths with their query and fragment', function (string $next) {
    expect(SafeRedirect::path($next))->toBe($next);
})->with([
    '/employees',
    '/employees/12?tab=leave#notes',
    '/',
]);

it('falls back to the dashboard for anything else', function (?string $next) {
    expect(SafeRedirect::path($next))->toBe('/');
})->with([
    'empty' => [''],
    'null' => [null],
    'absolute' => ['https://evil.example/'],
    'protocol relative' => ['//evil.example'],
    'backslash' => ['/\\evil.example'],
    'encoded slash' => ['/%2Fevil.example'],
    'encoded backslash' => ['/%5Cevil.example'],
    'control character' => ["/a\tb"],
    'encoded newline' => ['/a%0Ab'],
    'malformed encoding' => ['/%zz'],
    'invalid utf-8' => ['/%ff'],
    'relative' => ['employees'],
    'sign-in page' => ['/login'],
    'sign-in step' => ['/login/verify'],
    'set password' => ['/set-password?token=x'],
    'dot segments to sign-in' => ['/a/../login'],
    'encoded dot segments to sign-in' => ['/a/%2e%2e/login'],
    'too long' => ['/'.str_repeat('a', 512)],
]);

it('resolves dot segments like a browser', function () {
    expect(SafeRedirect::path('/a/./b/../c?x=1'))->toBe('/a/c?x=1')
        ->and(SafeRedirect::path('/a/b/..'))->toBe('/a/');
});
