<?php

use App\Support\DefaultContractTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The footer block goes last, so it does not show at the top of the page on screen.
     */
    public function up(): void
    {
        DB::table('contract_templates')->where('key', DefaultContractTemplate::definition()['key'])->update([
            'blocks' => json_encode(DefaultContractTemplate::blocks(), JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
