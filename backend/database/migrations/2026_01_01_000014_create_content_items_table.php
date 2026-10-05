<?php

declare(strict_types=1);

use App\Enums\ContentItemType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `content_items` from backend/database/migrations/0005, with the
 * composite index from 0012 and — critically — the FK change plus XOR CHECK
 * from 0015 folded in.
 *
 * One polymorphic content_items table with type-specific detail tables
 * (videos/links/notes) rather than per-type content tables, because
 * ContentItem is the common shape shared across VideoItem/LinkItem/Note in
 * frontend/src/types/course.ts.
 *
 * THE SINGLE-PARENT RULE — read before changing either foreign key
 * ------------------------------------------------------------------
 * A content item hangs off a Section OR a Group, never both, never neither.
 * That was originally only implied by application code; here it is a real
 * CHECK: (section_id IS NULL) <> (group_id IS NULL).
 *
 * Both parent FKs MUST be ON DELETE CASCADE for that check to be satisfiable.
 * The original 0005 had fk_content_items_group as ON DELETE SET NULL, and
 * 0015 had to drop and recreate it, for two reasons that are worth restating
 * because re-introducing SET NULL would silently break deletes:
 *
 *   1. Combined with the XOR check, SET NULL makes group deletion IMPOSSIBLE.
 *      MySQL would try to null out group_id on a row whose section_id is
 *      already NULL, violate the CHECK, and abort the whole DELETE.
 *   2. SET NULL was already wrong on its own terms: it orphaned content items
 *      into a both-parents-NULL state that NO listing query can ever return,
 *      so the rows became invisible garbage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('section_id', 36)->nullable();
            $table->char('group_id', 36)->nullable();
            $table->char('course_id', 36);
            $table->string('title', 190);
            $table->enum('type', ContentItemType::values());
            $table->integer('order_index')->default(0);
            $table->boolean('is_hidden')->default(false);
            $table->text('description')->nullable();
            $table->dateTime('start_at')->nullable();
            $table->dateTime('end_at')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index('course_id', 'idx_content_items_course');
            // The course-wide per-type listing filters on both columns; with
            // only course_id indexed, `type` was a post-scan condition.
            $table->index(['course_id', 'type'], 'idx_content_items_course_type');

            $table->foreign('section_id', 'fk_content_items_section')
                ->references('id')->on('sections')
                ->cascadeOnDelete();

            $table->foreign('group_id', 'fk_content_items_group')
                ->references('id')->on('course_groups')
                ->cascadeOnDelete();

            $table->foreign('course_id', 'fk_content_items_course')
                ->references('id')->on('courses')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE content_items ADD CONSTRAINT chk_content_items_single_parent CHECK ((section_id IS NULL) <> (group_id IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
