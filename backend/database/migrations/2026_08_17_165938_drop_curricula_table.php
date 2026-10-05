<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final step of the curricula/countries removal (see the governorates-drop
 * migration for context). Must run after the grades/students column drops
 * above, since those tables' FKs reference this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('curricula');
    }

    public function down(): void
    {
        Schema::create('curricula', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name', 100)->unique();
            $table->string('phone_code', 10);
            $table->unsignedTinyInteger('phone_digits');
            $table->string('currency_code', 10);
            $table->string('currency_symbol', 10);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }
};
