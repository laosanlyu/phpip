<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix classifier_type display_order so that the matter list view
     * (Matter::filter) correctly joins titles via display_order = 1, 2, 3.
     */
    public function up(): void
    {
        DB::table('classifier_type')->where('code', 'TIT')->update([
            'display_order' => 1,
        ]);
        DB::table('classifier_type')->where('code', 'TITOF')->update([
            'main_display' => 1,
            'display_order' => 2,
        ]);
        DB::table('classifier_type')->where('code', 'TM')->update([
            'display_order' => 3,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('classifier_type')->where('code', 'TIT')->update([
            'display_order' => 5,
        ]);
        DB::table('classifier_type')->where('code', 'TITOF')->update([
            'main_display' => 0,
            'display_order' => 127,
        ]);
        DB::table('classifier_type')->where('code', 'TM')->update([
            'display_order' => 5,
        ]);
    }
};
