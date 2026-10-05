<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * grade_sections — a new top-level admin-managed catalog ("أقسام") that
 * groups `grades` rows. See open decision 10.12. Same shape as `grades`
 * itself before it grew a `section_id`: flat, no timestamps, a globally
 * unique name, and a manual `order` column from day one (unlike `grades`,
 * which grew `order` in a later migration — this table starts with it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_sections', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name', 100);
            $table->unsignedInteger('order')->default(0);

            $table->unique('name', 'uq_grade_sections_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_sections');
    }
};
