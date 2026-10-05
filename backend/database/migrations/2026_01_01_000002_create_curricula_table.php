<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of backend/database/migrations/0018_create_curricula_table.sql.
 *
 * A curriculum row is one country's education market: display name, the phone
 * rules a number from that country must obey, and the currency prices are
 * quoted in.
 *
 * phone_digits is the count of digits a subscriber types in NATIONAL format,
 * INCLUDING the leading trunk zero — Egypt is 11 (01012345678), Jordan is 10
 * (0791234567), NOT the national significant number. Mixing those two
 * conventions up is the one real trap in this table.
 *
 * TINYINT UNSIGNED (not int) is preserved deliberately: it is what the live
 * schema has, and the empirical probe (tests/Feature/PdoTypeCastingTest.php)
 * confirms MySQL returns it to PHP as a native `int`, which is what
 * frontend/src/types/curriculum.ts declares (`phone_digits: number`).
 *
 * is_active lets an admin retire a country without deleting it, which matters
 * because grades hang off these rows under ON DELETE RESTRICT.
 *
 * MOVED EARLIER IN THE ORDER versus backend/: the .sql files created this
 * table at 0018 and then back-filled the foreign keys onto `grades` (0019) and
 * `students` (0020) with ALTER TABLE, because those tables already existed in
 * production. A fresh schema has no such constraint, so `curricula` is created
 * before its dependants and the FKs are declared inline. Same end state, three
 * fewer ALTER statements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curricula', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name', 100);
            $table->string('phone_code', 10);
            $table->unsignedTinyInteger('phone_digits');
            $table->string('currency_code', 10);
            $table->string('currency_symbol', 10);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('name', 'uq_curricula_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curricula');
    }
};
