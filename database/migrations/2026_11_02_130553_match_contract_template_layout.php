<?php

use App\Support\DefaultContractTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The master contract follows the layout of the office's Word contract. Issued contracts keep the
     * layout they were issued with.
     */
    public function up(): void
    {
        $definition = DefaultContractTemplate::definition();

        DB::table('contract_templates')->where('key', $definition['key'])->update([
            'description' => $definition['description'],
            'paper_size' => $definition['paper_size'],
            'font' => $definition['font'],
            'margin' => $definition['margin'],
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
