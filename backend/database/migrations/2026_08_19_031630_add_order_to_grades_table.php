<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grades gain an explicit `order` column so the admin-managed catalog can be
 * manually reordered (up/down in the UI) instead of always rendering
 * alphabetically. Existing rows are backfilled with sequential values by
 * their current `id` order — purely a starting point; the admin reorders
 * from the UI afterward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->unsignedInteger('order')->default(0)->after('name');
        });

        DB::table('grades')->orderBy('id')->get(['id'])->each(function ($row, int $index): void {
            DB::table('grades')->where('id', $row->id)->update(['order' => $index]);
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropColumn('order');
        });
    }
};
