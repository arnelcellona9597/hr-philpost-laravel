<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * The letterhead logo now sits on a white background. The file stored for it earlier is replaced
     * in place, so nothing that points at it has to change. Nothing happens if it was never stored.
     */
    public function up(): void
    {
        $bytes = file_get_contents(resource_path('branding/phlpost-letterhead-logo.png'));

        foreach (DB::table('media')->where('filename', 'phlpost-letterhead-logo.png')->get() as $file) {
            Storage::disk('local')->put($file->path, $bytes);

            DB::table('media')->where('id', $file->id)->update(['size' => strlen($bytes), 'updated_at' => now()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
