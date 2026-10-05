<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of the second half of backend/database/migrations/
 * 0002_create_teachers_table.sql — per frontend/src/types/teacher.ts
 * SocialLink.
 *
 * Split into its own migration file because it is its own table. The old
 * numbering bundled two CREATE TABLEs into one .sql only because the runner
 * was file-per-step; there is no reason to carry that coupling forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_social_links', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('teacher_id', 36);
            $table->string('platform', 50);
            $table->string('url', 255);
            $table->boolean('enabled')->default(true);
            $table->integer('order_index')->default(0);

            $table->foreign('teacher_id', 'fk_teacher_social_links_teacher')
                ->references('user_id')->on('teachers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_social_links');
    }
};
