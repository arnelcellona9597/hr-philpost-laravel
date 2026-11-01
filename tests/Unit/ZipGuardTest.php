<?php

use App\Support\ZipGuard;

function zipBytes(callable $fill): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $fill($zip);
    $zip->close();

    return (string) file_get_contents($path);
}

it('accepts an ordinary archive', function () {
    expect(ZipGuard::check(zipBytes(fn (ZipArchive $zip) => $zip->addFromString('a.xml', 'hello'))))->toBeNull();
});

it('rejects things that are not archives', function (string $bytes) {
    expect(ZipGuard::check($bytes))->toBe('This file is not a valid Excel workbook (.xlsx).');
})->with(['empty' => '', 'text' => str_repeat('text', 20), 'truncated' => "PK\x03\x04".str_repeat("\0", 40)]);

it('rejects too many parts', function () {
    $bytes = zipBytes(function (ZipArchive $zip): void {
        for ($part = 0; $part <= ZipGuard::MAX_ENTRIES; $part++) {
            $zip->addFromString("{$part}.xml", 'x');
        }
    });

    expect(ZipGuard::check($bytes))->toContain('too many parts');
});

it('rejects a zip bomb', function () {
    $bytes = zipBytes(fn (ZipArchive $zip) => $zip->addFromString('a.xml', str_repeat('0', 20 * 1024 * 1024)));

    expect(ZipGuard::check($bytes))->toContain('compressed suspiciously');
});

it('rejects more data than the limit', function () {
    $bytes = zipBytes(function (ZipArchive $zip): void {
        for ($part = 0; $part < 70; $part++) {
            $zip->addFromString("{$part}.xml", random_bytes(1024 * 1024));
            $zip->setCompressionName("{$part}.xml", ZipArchive::CM_STORE);
        }
    });

    expect(ZipGuard::check($bytes))->toContain('more than 60 MB');
});
