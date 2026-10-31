<?php

namespace App\Services;

use App\Exceptions\UserFacingException;
use App\Models\Media;
use App\Models\User;
use finfo;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Uploaded files live outside the web root and are only served through `/files/{id}`. Their type
 * comes from the file's content, never from the browser. SVG is excluded because it can carry scripts.
 */
class MediaStore
{
    public const IMAGE_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public const AVATAR_MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Save an upload after checking its size and detected type.
     *
     * @param  array<string, string>  $types  Allowed MIME types and the extension each is stored with
     *
     * @throws UserFacingException when the file isn't acceptable
     */
    public function store(UploadedFile $file, string $alt, array $types, int $maxBytes = self::MAX_BYTES): Media
    {
        if ($file->getSize() > min($maxBytes, self::MAX_BYTES)) {
            throw new UserFacingException('The file must be '.round($maxBytes / 1024 / 1024).' MB or smaller.');
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        if (! isset($types[$detected])) {
            $names = str_replace('JPEG', 'JPG', implode(', ', array_map(fn (string $type): string => Str::upper(Str::after($type, '/')), array_keys($types))));

            throw new UserFacingException("This file type isn't allowed. Use {$names}.");
        }

        $path = 'media/'.Str::uuid().'.'.$types[$detected];
        Storage::disk('local')->put($path, $file->getContent());

        return Media::query()->create([
            'filename' => Str::substr(preg_replace('/[^\w.\-]+/', '_', $file->getClientOriginalName()), -120) ?: 'upload',
            'path' => $path,
            'mime_type' => $detected,
            'size' => $file->getSize(),
            'alt' => $alt,
        ]);
    }

    /**
     * Set or remove (null) a user's own picture. The old file is deleted so personal images don't pile up.
     *
     * @throws UserFacingException when the picture isn't acceptable
     */
    public function setAvatar(User $user, ?UploadedFile $file): void
    {
        $previous = $user->avatar()->first();
        $created = $file ? $this->store($file, "Profile picture of {$user->name}", self::IMAGE_TYPES, self::AVATAR_MAX_BYTES) : null;

        try {
            $user->forceFill(['avatar_id' => $created?->id])->save();
        } catch (Throwable $exception) {
            if ($created !== null) {
                $this->delete($created);
            }

            throw $exception;
        }

        if ($previous !== null) {
            $this->delete($previous);
        }
    }

    /**
     * Remove a file and its record, unless something else still uses it.
     */
    public function delete(Media $media): void
    {
        try {
            $media->delete();
        } catch (QueryException) {
            return;
        }

        Storage::disk('local')->delete($media->path);
    }
}
