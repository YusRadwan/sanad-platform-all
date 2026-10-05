<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part of the curricula/countries removal (see the governorates-drop
 * migration for context). Students no longer name a curriculum at
 * registration time — there is only one supported country, hardcoded on the
 * frontend — so the nullable FK and its column are dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign('fk_students_curriculum');
            $table->dropColumn('curriculum_id');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->char('curriculum_id', 36)->nullable();

            $table->foreign('curriculum_id', 'fk_students_curriculum')
                ->references('id')->on('curricula')
                ->nullOnDelete();
        });
    }
};
