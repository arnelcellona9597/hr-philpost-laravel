<?php

use App\Support\DefaultContractTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fine print keeps the contract on the three pages its acknowledgement states.
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
