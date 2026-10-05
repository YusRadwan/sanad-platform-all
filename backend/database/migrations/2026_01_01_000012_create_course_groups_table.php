<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `course_groups` from backend/database/migrations/0005.
 *
 * Groups act as an OPTIONAL organisational layer over a course's content,
 * independent of sections — a content item hangs off one or the other, never
 * both (see the XOR check on content_items).
 *
 * Named `course_groups` and not `groups` because `groups` is close enough to
 * a reserved word in several SQL dialects to be a nuisance, and because the
 * table is meaningless outside a course.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_groups', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('course_id', 36);
            $table->string('title', 190);
            $table->boolean('is_hidden')->default(false);
            $table->integer('order_index')->default(0);

            $table->foreign('course_id', 'fk_course_groups_course')
                ->references('id')->on('courses')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_groups');
    }
};
