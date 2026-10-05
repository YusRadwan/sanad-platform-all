<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "طلب مدرس جديد" — open decision 10.23.
 *
 * A public visitor applying to teach on the platform (JoinUsSection.tsx's
 * "ابدأ التدريس معنا الآن" button) submits this once; an admin reviews it on
 * a dedicated read-only sidebar page. There is no approve/reject workflow —
 * the request explicitly asked only for the data to be captured and shown.
 *
 * Deliberately NOT modelled with foreign keys into `grade_sections`/`grades`
 * (unlike the student signup wizard's `sectionId`): this is a one-shot,
 * point-in-time submission record, not a live entity that must track catalog
 * renames. `section_name`/`grades`/`subject` are plain snapshot strings —
 * exactly what the visitor picked at submission time, immune to a later
 * catalog rename or deletion, and needing no eager-loaded relation to render
 * on the admin list.
 *
 * `grades` and `social_links` are JSON arrays of strings, the same
 * "JSON array of name strings, no pivot table" convention `teachers.grades`/
 * `.subjects` already use elsewhere in this schema.
 *
 * TIMESTAMPS: only `created_at` — this row is never updated after submission,
 * same treatment as `enrollment_codes` and `subscriptions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_applications', function (Blueprint $table) {
            $table->char('id', 36)->primary();

            // The four separate name inputs, already joined into one string by
            // the frontend before submission — exactly how SignupPage.tsx
            // joins its four name parts into `fullName` before POSTing.
            $table->string('full_name', 150);

            $table->string('phone', 20);
            $table->string('whatsapp', 20);
            $table->string('governorate', 100);
            $table->unsignedTinyInteger('age');
            $table->unsignedTinyInteger('years_of_experience');
            $table->string('subject', 150);

            // Snapshot of the chosen grade_sections catalog item's NAME, not
            // its id — see class docblock.
            $table->string('section_name', 100);

            // JSON array of grade-name strings — the grades this teacher can
            // teach, multi-select on the frontend.
            $table->json('grades');

            // JSON array of URL/handle strings — social/educational profile links.
            $table->json('social_links');

            $table->dateTime('created_at')->useCurrent();

            $table->index('created_at', 'idx_teacher_applications_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_applications');
    }
};
