<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `teachers.whatsapp_support_numbers` — a per-teacher list of extra WhatsApp
 * contacts an admin can attach (e.g. "مسؤول المادة: 01012345678"), each entry
 * a single free-text string exactly as `AddTeacherComponents/TeacherWhatsAppSupport.tsx`
 * collects it (label and number typed together, not two separate fields).
 *
 * THIS CLOSES A REAL, PRE-EXISTING PRODUCT GAP, NOT A REWRITE PORT.
 *
 * `StoreTeacherRequest`/`UpdateTeacherRequest` previously documented this
 * field as "accepted, never persisted" — matching backend/, which also had no
 * column for it. The admin form has been collecting these numbers into thin
 * air since before this rewrite existed: nothing ever reached a database, and
 * the public teacher profile page had no way to show them even if it had.
 * That silent no-op is the bug being fixed here, on explicit product request.
 *
 * JSON array of strings, mirroring `subjects`/`grades`' shape and clear-on-[]
 * semantics exactly (see App\Actions\Teacher\UpdateTeacher's ARRAY_PROFILE_FIELDS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->json('whatsapp_support_numbers')->nullable()->after('profile_photo_url');
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn('whatsapp_support_numbers');
        });
    }
};
