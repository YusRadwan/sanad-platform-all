<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "ميعاد المجموعة" upgraded from one free-text field to real structured
 * slots — day + time, more than one per group — plus a new "شهر أو حصة"
 * billing-cycle field, both explicit teacher requests.
 *
 * `center_groups.schedule_text` STAYS, unchanged in shape: it is now a
 * SERVER-COMPUTED summary joined from this group's `center_group_schedules`
 * rows (see CenterGroupController's formatTimeArabic()/scheduleTextFrom())
 * rather than admin-typed free text, so every existing reader of
 * `schedule_text` (the attendance/report group pickers, etc.) keeps working
 * with zero changes on its side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('center_groups', function (Blueprint $table) {
            $table->string('billing_type', 20)->default('حصة')->after('schedule_text');
        });

        Schema::create('center_group_schedules', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('center_group_id', 36);
            $table->string('day_of_week', 20);
            $table->time('time_of_day');
            $table->unsignedTinyInteger('order_index')->default(0);

            $table->index('center_group_id', 'idx_center_group_schedules_group');

            $table->foreign('center_group_id', 'fk_center_group_schedules_group')
                ->references('id')->on('center_groups')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_group_schedules');

        Schema::table('center_groups', function (Blueprint $table) {
            $table->dropColumn('billing_type');
        });
    }
};
