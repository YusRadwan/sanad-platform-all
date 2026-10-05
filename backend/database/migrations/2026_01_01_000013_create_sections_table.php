<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `sections` from backend/database/migrations/0005.
 *
 * The primary organisational layer of a course's syllabus (the other being
 * course_groups). Cascades from its course.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('course_id', 36);
            $table->string('title', 190);
            $table->integer('order_index')->default(0);

            $table->foreign('course_id', 'fk_sections_course')
                ->references('id')->on('courses')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};
