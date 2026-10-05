<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `teachers.center_enabled` — an ADMIN-ONLY switch that gates access to the
 * ENTIRE Center System (نظام السنتر) for one instructor, explicit request.
 *
 * ┌── DEFAULT TRUE, ON PURPOSE — NOT "SAFE BY DEFAULT" IN THE USUAL SENSE. ──┐
 * │ The Center System already has real, live teachers using it (groups,     │
 * │ sessions, attendance, payment tracking rows already exist in production  │
 * │ before this column did). Defaulting every EXISTING row to `true` is the  │
 * │ only choice that does not silently lock those teachers out the moment    │
 * │ this migrates — a `false` default would turn a permission-scoping        │
 * │ feature into an accidental outage. App\Actions\Teacher\CreateTeacher     │
 * │ overrides this default to an explicit opt-in (`false` unless the admin   │
 * │ ticks the box) for NEWLY CREATED teachers going forward — see that       │
 * │ Action's own note. The two defaults intentionally differ.               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── TURNING IT OFF NEVER DELETES ANYTHING. ────────────────────────────────┐
 * │ Explicit requirement: "لا يتم حذف أي بيانات خاصة بنظام سنتر هذا المدرس،  │
 * │ وعند فتحه يظهر مرة أخرى بدون أي حذف أو مشاكل". This column is read in    │
 * │ EXACTLY ONE place — App\Http\Middleware\EnsureCenterEnabled, gating the   │
 * │ `/center/*` instructor routes — never by a query that filters or deletes  │
 * │ rows. A teacher's groups/sessions/attendance/payment records sit          │
 * │ untouched in the database the whole time access is off, and reappear      │
 * │ exactly as they were the moment an admin flips it back on.               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Same shape as `teachers.login_blocked` (migration 000037): boolean,
 * NOT NULL, lives on `teachers` (not `users`) because it is meaningful only
 * for instructor accounts and the only surface that reads/writes it is
 * admin-facing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->boolean('center_enabled')
                ->nullable(false)
                ->default(true)
                ->after('login_blocked');
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn('center_enabled');
        });
    }
};
