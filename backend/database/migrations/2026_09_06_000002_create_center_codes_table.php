<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * center_codes — one 6-char code (1 capital letter + 5 digits), bound to
 * exactly ONE teacher, REUSABLE by many students (unlike enrollment_codes,
 * which is single-use). A code is how a teacher's outside-the-platform
 * center students find their way to that teacher's account.
 *
 * `uq_center_codes_code` IS A LOAD-BEARING LITERAL STRING — parsed by
 * App\Actions\Center\GenerateCenterCode's collision-retry catch, exactly the
 * same convention as `uq_enrollment_codes_code`. Do not rename.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_codes', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('code', 6);
            $table->char('teacher_id', 36);
            $table->char('created_by', 36)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->useCurrent();

            $table->unique('code', 'uq_center_codes_code');
            $table->index('teacher_id', 'idx_center_codes_teacher');

            $table->foreign('teacher_id', 'fk_center_codes_teacher')
                ->references('user_id')->on('teachers')
                ->cascadeOnDelete();

            $table->foreign('created_by', 'fk_center_codes_creator')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_codes');
    }
};
