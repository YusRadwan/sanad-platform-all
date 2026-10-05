<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `notes` from backend/database/migrations/0005.
 *
 * Type-specific detail table for content_items.type = 'note'. Carries either
 * inline text (content_text) or an uploaded document (file_name/file_path) —
 * both are nullable because a note may legitimately be one or the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->char('content_item_id', 36)->primary();
            $table->text('content_text')->nullable();
            $table->string('file_name', 255)->nullable();
            $table->string('file_path', 255)->nullable();

            $table->foreign('content_item_id', 'fk_notes_content_item')
                ->references('id')->on('content_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
