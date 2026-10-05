<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `nationalities` from backend/database/migrations/0016.
 *
 * Third of the three admin-managed option catalogs behind the "التصنيفات"
 * step of the teacher-creation form. Identical minimal shape to grades and
 * subjects so all three can be served by one repository/service/controller
 * keyed by a validated {type} route param.
 *
 * Unlike `grades`, this one is NOT curriculum-scoped — a nationality is a
 * property of a person, not of a country's education market.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nationalities', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name', 100);

            $table->unique('name', 'uq_nationalities_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nationalities');
    }
};
