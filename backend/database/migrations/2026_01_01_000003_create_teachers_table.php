<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of backend/database/migrations/0002_create_teachers_table.sql plus
 * 0017_add_teacher_profile_photo.sql (folded in — see below).
 *
 * 1:1 extension of `users` where role='instructor'. The primary key IS the
 * foreign key (user_id), which is what makes courses.teacher_id ->
 * teachers.user_id work later.
 *
 * THREE distinct images, deliberately not interchangeable:
 *   users.avatar_url             small circular card/profile photo
 *   teachers.banner_url          wide hero/cover background
 *   teachers.profile_photo_url   the "نبذة عن المدرس" editorial portrait,
 *                                shown only on the teacher's dashboard home
 *                                and the public profile page
 *
 * `profile_photo_url` was ALTER-added by 0017 in backend/; here it is declared
 * inline in its final position (AFTER banner_url), since a fresh schema has no
 * reason to replay the historical column-append.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->char('user_id', 36)->primary();
            $table->string('phone', 20)->nullable();
            $table->string('specialization', 190)->nullable();
            $table->text('bio')->nullable();
            $table->json('subjects')->nullable();
            $table->json('grades')->nullable();
            $table->string('banner_url', 255)->nullable();
            $table->string('profile_photo_url', 255)->nullable();
            $table->decimal('rating', 3, 2)->default(0);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable()->useCurrentOnUpdate();

            $table->foreign('user_id', 'fk_teachers_user')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teachers');
    }
};
