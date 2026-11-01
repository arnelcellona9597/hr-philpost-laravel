<?php

use App\Support\DefaultContractScopes;
use App\Support\DefaultContractTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contract_scopes', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->nullable()->unique();
            $table->string('name', 120)->unique();
            $table->string('position', 150)->nullable()->index();
            $table->string('office', 160)->nullable();
            $table->string('place_of_service', 160)->nullable();
            $table->json('items');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('contract_scope_id')->nullable()->after('template_id')->constrained()->nullOnDelete();
        });

        foreach (DefaultContractScopes::all() as $scope) {
            DB::table('contract_scopes')->insert([
                ...$scope,
                'items' => json_encode($scope['items'], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $definition = DefaultContractTemplate::definition();

        DB::table('contract_templates')->where('key', $definition['key'])->update([
            'name' => $definition['name'],
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
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_scope_id');
        });

        Schema::dropIfExists('contract_scopes');
    }
};
