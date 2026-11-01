<?php

use App\Support\DefaultContractTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('contract_templates')->exists()) {
            return;
        }

        DB::table('contract_templates')->insert([
            ...DefaultContractTemplate::definition(),
            'blocks' => json_encode(DefaultContractTemplate::blocks(), JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('contract_templates')
            ->where('key', DefaultContractTemplate::definition()['key'])
            ->whereNotExists(fn ($query) => $query->from('contracts')->whereColumn('contracts.template_id', 'contract_templates.id'))
            ->delete();
    }
};
