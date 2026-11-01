<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Uploaded workbooks waiting between "check" and "confirm", held for an hour and only for the
 * person who uploaded them.
 *
 * @phpstan-type PendingMeta array{user_id: int, file_name: string, hint: string, created_at: int}
 */
class PendingImports
{
    private const DIRECTORY = 'imports';

    private const LIFETIME_SECONDS = 3600;

    public function stage(string $bytes, int $userId, string $fileName, string $hint): string
    {
        $this->removeExpired();

        $token = bin2hex(random_bytes(16));
        $disk = Storage::disk('local');

        $disk->put($this->pendingPath($token), $bytes);
        $disk->put($this->metaPath($token), (string) json_encode([
            'user_id' => $userId,
            'file_name' => $fileName,
            'hint' => $hint,
            'created_at' => now()->getTimestamp(),
        ]));

        return $token;
    }

    /**
     * Take sole ownership of a staged upload with an atomic rename, so two "Confirm" clicks cannot
     * both import it. Follow up with release() or finish().
     *
     * @return array{path: string, meta: PendingMeta}|null
     */
    public function claim(string $token, int $userId, string $hintPrefix): ?array
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($this->pendingPath($token)) || ! $disk->exists($this->metaPath($token))) {
            return null;
        }

        $meta = json_decode((string) $disk->get($this->metaPath($token)), true);

        if ($meta['user_id'] !== $userId || ! str_starts_with($meta['hint'], $hintPrefix) || now()->getTimestamp() - $meta['created_at'] > self::LIFETIME_SECONDS) {
            return null;
        }

        $claimed = $disk->path($this->claimedPath($token));

        if (! @rename($disk->path($this->pendingPath($token)), $claimed)) {
            return null;
        }

        return ['path' => $claimed, 'meta' => $meta];
    }

    /**
     * Put a claimed upload back, because the import did not go ahead.
     */
    public function release(string $token): void
    {
        $disk = Storage::disk('local');

        if ($disk->exists($this->claimedPath($token))) {
            rename($disk->path($this->claimedPath($token)), $disk->path($this->pendingPath($token)));
        }
    }

    /**
     * Remove a staged upload. An archive name keeps the workbook with the import history.
     */
    public function finish(string $token, ?string $archiveName = null): void
    {
        $disk = Storage::disk('local');

        if ($archiveName !== null && $disk->exists($this->claimedPath($token))) {
            rename($disk->path($this->claimedPath($token)), $disk->path(self::DIRECTORY."/{$archiveName}"));
        }

        $disk->delete([$this->claimedPath($token), $this->metaPath($token)]);
    }

    private function removeExpired(): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files(self::DIRECTORY) as $file) {
            if (str_contains($file, '/pending-') && now()->getTimestamp() - $disk->lastModified($file) > self::LIFETIME_SECONDS) {
                $disk->delete($file);
            }
        }
    }

    private function pendingPath(string $token): string
    {
        return self::DIRECTORY."/pending-{$token}.xlsx";
    }

    private function metaPath(string $token): string
    {
        return self::DIRECTORY."/pending-{$token}.json";
    }

    private function claimedPath(string $token): string
    {
        return self::DIRECTORY."/committing-{$token}.xlsx";
    }
}
