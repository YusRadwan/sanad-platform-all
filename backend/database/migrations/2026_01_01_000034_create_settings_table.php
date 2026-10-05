<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `settings` from backend/database/migrations/0006.
 *
 * A key/value store for platform settings, with the VALUE as native JSON so a
 * setting can be a scalar, an object or a list without a type column.
 *
 * `key` and `value` are both reserved words in MySQL. Laravel's grammar quotes
 * every identifier with backticks unconditionally, so unlike the hand-written
 * SQL (which had to remember the backticks manually) there is nothing to get
 * wrong here.
 *
 * MULTI-TENANCY NOTE (Stage 3): this table is currently GLOBAL — one row per
 * key for the whole platform. In a multi-teacher SaaS, most of these settings
 * will need to become per-tenant. That is a schema change (a composite PK of
 * (tenant_id, key), or a separate teacher_settings table), NOT something that
 * can be bolted on with a scope. Flagged in the report rather than
 * pre-emptively changed, because inventing a tenant column now — before the
 * tenancy model is decided — would be guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->dateTime('updated_at')->nullable()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
