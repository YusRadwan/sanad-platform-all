<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Center System — adds course scope (platform/center/both).
 *
 * DEFAULT 'platform' preserves every existing course's current behaviour
 * exactly: it keeps showing in the public catalogue and stays invisible to
 * "center courses" listings. No backfill needed beyond the column default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('visibility', 20)->default('platform')->after('is_published');
            $table->index('visibility', 'idx_courses_visibility');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex('idx_courses_visibility');
            $table->dropColumn('visibility');
        });
    }
};
