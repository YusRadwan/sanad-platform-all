<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds real visibility-scheduling to `course_groups` — open decision 10.21.
 *
 * GroupManagement.tsx has shipped date/time pickers and a "قواعد ظهور" rule
 * banner describing automatic show/hide scheduling since before this
 * migration, but `course_groups` never had the columns to back it: the form
 * posted `startDate`/`endDate` into a request body that only ever validated
 * `course_id`/`title`/`is_hidden`, so every date picked in that UI was
 * silently discarded. This migration is what makes the promise real.
 *
 * Both columns are NULLABLE, and NULL is not "unset waiting for a value" —
 * it is a real, permanent state: NULL `start_at` means "no start gate, has
 * always been visible"; NULL `end_at` means "no end gate, visible forever".
 * A group created without picking any date is fully visible immediately,
 * matching CourseGroup::isCurrentlyVisible()'s NULL-is-satisfied semantics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_groups', function (Blueprint $table) {
            $table->dateTime('start_at')->nullable()->after('is_hidden');
            $table->dateTime('end_at')->nullable()->after('start_at');
        });
    }

    public function down(): void
    {
        Schema::table('course_groups', function (Blueprint $table) {
            $table->dropColumn(['start_at', 'end_at']);
        });
    }
};
