<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `settings` — the platform's small, PUBLIC, admin-written display bag.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS CLASS EXTENDS `Model`, **NOT** `App\Models\BaseModel`.              ║
 * ║                                                                          ║
 * ║ BaseModel carries `HasUuids`, which generates a UUID into the primary    ║
 * ║ key on every create. Here the primary key IS the setting name — the      ║
 * ║ literal strings 'settings', 'socialLinks', 'supportNumbers' — so         ║
 * ║ inheriting that trait would overwrite the caller's key with a random     ║
 * ║ UUID and produce a new row on every single save instead of an upsert.    ║
 * ║ The three schema facts BaseModel encodes are restated below by hand      ║
 * ║ because two of them ($keyType, $incrementing) happen to be right for a   ║
 * ║ different reason and the third (UUID generation) is actively wrong.      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── `key` AND `value` ARE BOTH MySQL RESERVED WORDS. ──────────────────────┐
 * │ Laravel's query grammar wraps EVERY identifier in backticks              │
 * │ unconditionally (Grammar::wrapValue), so `Setting::find('settings')`,    │
 * │ `->where('key', …)`, `->whereIn('key', …)` and the insert/update         │
 * │ builders are all safe with no special handling — unlike backend/         │
 * │ SettingsRepository, which had to spell the backticks out by hand in      │
 * │ every statement.                                                         │
 * │                                                                          │
 * │ THE EXEMPTION IS RAW SQL. `DB::statement`, `DB::raw`, `whereRaw`,        │
 * │ `selectRaw` and `orderByRaw` bypass the grammar entirely. If anything    │
 * │ in this application ever writes raw SQL against this table it MUST       │
 * │ backtick both column names itself; `SELECT key FROM settings` is a       │
 * │ syntax error, not a silent wrong answer, but only in the SELECT case —   │
 * │ some MySQL versions parse a bare `value` in other positions.             │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── $timestamps = false, YET THE TABLE HAS `updated_at`. ──────────────────┐
 * │ Not a contradiction. The column is declared                              │
 * │ `DATETIME NULL ON UPDATE CURRENT_TIMESTAMP` (migration 000034, ported    │
 * │ from backend/0006), so MySQL maintains it ITSELF on every UPDATE. There  │
 * │ is no `created_at`, so Eloquent's timestamp management — which writes    │
 * │ BOTH columns — would fail on the missing one. Letting the database own   │
 * │ the column is also what backend/ did: its INSERT … ON DUPLICATE KEY      │
 * │ UPDATE never mentions `updated_at` either.                               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * MULTI-TENANCY: this table is GLOBAL — one row per key for the whole
 * platform. Per-tenant settings need a composite (tenant_id, key) primary key,
 * i.e. a schema change, not a scope. Recorded on the migration and in
 * open-decisions; deliberately not pre-empted here.
 */
class Setting extends Model
{
    protected $table = 'settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** See the docblock: MySQL owns `updated_at`, and there is no `created_at`. */
    public $timestamps = false;

    protected $fillable = [
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            /*
             * The column is native JSON. The 'array' cast decodes on read and
             * encodes on write, which replaces backend/SettingsRepository's
             * explicit json_decode(..., true) / json_encode(...) pair.
             */
            'value' => 'array',
        ];
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ ENCODING OVERRIDE: `JSON_UNESCAPED_UNICODE` ON THE **STORED BYTES**. ║
     * ║                                                                      ║
     * ║ Laravel's HasAttributes::asJson() encodes with no unicode flag, so    ║
     * ║ Arabic would be persisted as a wall of backslash-u escapes.           ║
     * ║ backend/SettingsRepository::set() passes JSON_UNESCAPED_UNICODE and   ║
     * ║ stores literal UTF-8.                                                 ║
     * ║                                                                      ║
     * ║ THE WIRE FORMAT IS UNAFFECTED EITHER WAY — json_decode() produces     ║
     * ║ identical PHP values from both spellings, and ApiResponse re-encodes  ║
     * ║ every response with JSON_UNESCAPED_UNICODE regardless. So this is     ║
     * ║ NOT a bug fix; it is byte-fidelity of what lands in the database, so  ║
     * ║ that an operator running `SELECT value FROM settings` in a MySQL      ║
     * ║ client sees 'شروط الاستخدام' and not a wall of escapes, exactly as    ║
     * ║ they do against the current production table. A migration that ever   ║
     * ║ diffs the two databases row-for-row would otherwise report every      ║
     * ║ Arabic-bearing row as changed.                                        ║
     * ║                                                                      ║
     * ║ JSON_UNESCAPED_SLASHES is NOT added: backend/ does not pass it here   ║
     * ║ (only ApiResponse does, on the way out), so a stored URL keeps its    ║
     * ║ `http:\/\/` escaping in the column, matching the live table.          ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    protected function asJson($value, $flags = 0)
    {
        return parent::asJson($value, $flags | JSON_UNESCAPED_UNICODE);
    }
}
