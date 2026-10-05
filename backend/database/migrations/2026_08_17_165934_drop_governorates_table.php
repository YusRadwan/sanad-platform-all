<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the curricula/countries system (Stage 2) entirely, per approved
 * plan: a single hardcoded country (Egypt) is now baked into the frontend
 * instead of an admin-managed list of countries.
 *
 * governorates is entirely curriculum-scoped and referenced by nothing else
 * (students.governorate stores a free-text name, not a foreign key), so it is
 * dropped outright — first, because it holds a NOT NULL FK to curricula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('governorates');
    }

    public function down(): void
    {
        Schema::create('governorates', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name', 100);
            $table->char('curriculum_id', 36);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['name', 'curriculum_id'], 'uq_governorates_name_curriculum');

            $table->foreign('curriculum_id', 'fk_governorates_curriculum')
                ->references('id')->on('curricula')
                ->cascadeOnDelete();
        });
    }
};
