<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `videos` from backend/database/migrations/0005.
 *
 * Type-specific detail table for content_items.type = 'video'. The primary key
 * IS the foreign key, which makes the 1:1 with content_items structural rather
 * than merely conventional — no UNIQUE index is needed on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->char('content_item_id', 36)->primary();
            $table->string('video_url', 255);
            $table->integer('duration')->nullable();
            $table->boolean('is_preview')->default(false);
            $table->string('provider', 50)->nullable();
            $table->string('thumbnail_url', 255)->nullable();

            $table->foreign('content_item_id', 'fk_videos_content_item')
                ->references('id')->on('content_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
