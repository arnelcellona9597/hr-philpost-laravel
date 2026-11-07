<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LETTERHEAD = [
        'letterhead_name' => "PHILIPPINE POSTAL CORPORATION\nAREA 7 EASTERN MINANAO",
        'letterhead_details' => "R. Magsaysay ext. cor. Roxas Avenue, 8000 Davao City\nhr7official2022@gmail.com",
        'letterhead_section' => 'Human Resource Management Section',
    ];

    /**
     * The office letterhead printed on certificates and the employee profile. Only wording that is
     * still blank is filled in, so a letterhead someone already typed is left as it is.
     */
    public function up(): void
    {
        foreach (self::LETTERHEAD as $column => $value) {
            DB::table('site_settings')
                ->where(fn ($blank) => $blank->whereNull($column)->orWhere($column, ''))
                ->update([$column => $value]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::LETTERHEAD as $column => $value) {
            DB::table('site_settings')->where($column, $value)->update([$column => null]);
        }
    }
};
