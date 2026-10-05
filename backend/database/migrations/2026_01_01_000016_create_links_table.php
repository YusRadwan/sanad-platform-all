<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `links` from backend/database/migrations/0005.
 *
 * Type-specific detail table for content_items.type = 'link'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->char('content_item_id', 36)->primary();
            $table->string('url', 255);

            $table->foreign('content_item_id', 'fk_links_content_item')
                ->references('id')->on('content_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
