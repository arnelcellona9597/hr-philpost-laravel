<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The wide PHLPOST logo (Bagong Pilipinas emblem, wordmark and courier) used in the office letterhead.
     * It becomes the letterhead logo. The logo file that was there is left in place, unused.
     */
    public function up(): void
    {
        if (! DB::table('site_settings')->exists()) {
            return;
        }

        $path = 'media/'.Str::uuid().'.png';
        $bytes = file_get_contents(resource_path('branding/phlpost-letterhead-logo.png'));

        Storage::disk('local')->put($path, $bytes);

        $id = DB::table('media')->insertGetId([
            'filename' => 'phlpost-letterhead-logo.png',
            'path' => $path,
            'mime_type' => 'image/png',
            'size' => strlen($bytes),
            'alt' => 'PHLPOST letterhead logo',
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('site_settings')->update(['letterhead_logo_id' => $id, 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $media = DB::table('media')->where('filename', 'phlpost-letterhead-logo.png')->get();

        DB::table('site_settings')->whereIn('letterhead_logo_id', $media->pluck('id'))->update(['letterhead_logo_id' => null]);

        foreach ($media as $file) {
            Storage::disk('local')->delete($file->path);
            DB::table('media')->where('id', $file->id)->delete();
        }
    }
};
