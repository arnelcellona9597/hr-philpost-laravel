<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class FileController extends Controller
{
    /**
     * Files never live in a public folder. Anything a visitor may not read looks like it doesn't exist.
     */
    public function show(Request $request, Media $media): Response
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $media), 404);

        $disk = Storage::disk('local');

        abort_unless($disk->exists($media->path), 404);

        return $disk->response($media->path, $media->filename, [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => $media->is_public ? 'public, max-age=300' : 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            // An uploaded file must never run code on this origin.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], 'inline');
    }
}
