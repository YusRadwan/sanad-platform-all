<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared base for every domain model.
 *
 * It exists to encode three schema-wide facts ONCE, rather than repeating (and
 * eventually mis-repeating) them on 34 classes:
 *
 * 1. PRIMARY KEYS ARE CHAR(36) UUIDs, NOT AUTO-INCREMENT INTEGERS.
 *    Eloquent's default is an incrementing int, which would make every insert
 *    fail against this schema. HasUuids also gives us server-side generation
 *    on create, replacing backend/'s scattered UUID() SQL calls and PHP-side
 *    generators — and it is a first-party trait, not a package.
 *
 *    This also matters for the multi-tenant future: opaque UUID keys mean a
 *    resource id never leaks a tenant's row COUNT, and ids stay globally
 *    unique if data is ever sharded per tenant.
 *
 *    ┌─ UUID VERSION: v7 SINCE THE LARAVEL 12 UPGRADE ──────────────────┐
 *    │ Laravel 12 redefined `HasUuids::newUniqueId()` from              │
 *    │ `Str::orderedUuid()` (a time-ordered COMB v4) to `Str::uuid7()`. │
 *    │ We keep `HasUuids` and accept v7 deliberately:                   │
 *    │                                                                  │
 *    │  * The wire format is unchanged — still a 36-char dashed UUID    │
 *    │    string, so CHAR(36), every FK, and the frontend's `string`    │
 *    │    id types are all untouched. Verified: strlen === 36.          │
 *    │                                                                  │
 *    │  * No privacy regression. Laravel 11's orderedUuid ALREADY       │
 *    │    embedded a timestamp prefix (that is what "ordered" meant),   │
 *    │    so creation-time ordering was derivable before and after.     │
 *    │    v7 just makes it a standardised field instead of a Laravel    │
 *    │    convention.                                                   │
 *    │                                                                  │
 *    │  * It is better for this schema. A monotonic PK keeps InnoDB     │
 *    │    clustered-index inserts append-only instead of scattering     │
 *    │    page splits across a CHAR(36) key.                            │
 *    │                                                                  │
 *    │ Mixed v4/v7 ids can coexist in one column — nothing parses the   │
 *    │ version — so pre-upgrade rows need no migration.                 │
 *    │                                                                  │
 *    │ To pin the old behaviour instead, swap in                        │
 *    │ Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids.          │
 *    │ (`HasVersion7Uuids` was REMOVED in 12.x — do not reach for it.)  │
 *    └──────────────────────────────────────────────────────────────────┘
 *
 * 2. TIMESTAMP COLUMNS ARE DATETIME, NOT TIMESTAMP, ON MOST TABLES.
 *    Declared per-model via $casts where it matters.
 *
 * 3. THE FRONTEND'S TYPE CONTRACT IS PRESERVED BY EXPLICIT CASTS.
 *    Proven empirically (tests/Feature/PdoTypeCastingTest.php): MySQL returns
 *    TINYINT(1) as PHP int 1/0 and DECIMAL as a PHP string. The frontend's
 *    TypeScript declares booleans as `boolean` (see
 *    frontend/src/types/curriculum.ts `is_active: boolean`), so every
 *    boolean-ish column MUST carry a 'boolean' cast or the JSON ships 1/0 and
 *    breaks strict consumers. Each model below does this explicitly; there is
 *    no magic inference to rely on.
 *
 * Deliberately NOT here: relationships, scopes, accessors, business rules.
 * Those are Stage 1+ work. Stage 0 only guarantees that a model which does
 * exist talks to the schema correctly.
 */
abstract class BaseModel extends Model
{
    use HasUuids;

    /** CHAR(36), not an integer. */
    protected $keyType = 'string';

    public $incrementing = false;
}
