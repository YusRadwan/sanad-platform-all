<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The "nationality" catalog is removed platform-wide per explicit
        // user request. It was already backend-inert: nothing in the schema
        // ever referenced a nationality value — StoreTeacherRequest/
        // UpdateTeacherRequest accepted the field and CreateTeacher/
        // UpdateTeacher silently dropped it, so dropping this table loses
        // no functional data path, only the catalog rows themselves.
        Schema::dropIfExists('nationalities');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('nationalities', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name', 100);

            $table->unique('name', 'uq_nationalities_name');
        });
    }
};
