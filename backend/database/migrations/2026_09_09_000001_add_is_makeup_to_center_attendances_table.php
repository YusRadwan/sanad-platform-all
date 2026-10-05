<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "حصة تعويضية" (decision: teacher explicit request). A student's home group
 * is the first group they ever attended for this teacher (`center_group_
 * students`'s earliest row). Attending a DIFFERENT group's session, after the
 * teacher confirms the cross-group warning, marks THIS attendance row
 * `is_makeup = true` and — the whole point — never inserts a `center_group_
 * students` row for the new group, so the student is never silently
 * transferred and the new group's roster/absence counts stay accurate.
 * Default false: every pre-existing row and every ordinary same-group
 * check-in is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('center_attendances', function (Blueprint $table) {
            $table->boolean('is_makeup')->default(false)->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('center_attendances', function (Blueprint $table) {
            $table->dropColumn('is_makeup');
        });
    }
};
