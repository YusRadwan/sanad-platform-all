<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `subjects` from backend/database/migrations/0005, with the UNIQUE
 * added by 0016 folded in.
 *
 * Deliberately has no timestamps: neither pre-existing catalog table has them,
 * the admin UI shows nothing but the name, and adding them to only one of the
 * three catalogs (grades / subjects / nationalities) would make the shared
 * CRUD path asymmetric for no gain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name', 100);

            $table->unique('name', 'uq_subjects_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
