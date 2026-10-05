<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Gives a backed enum the ->values() helper the migrations feed straight into
 * Blueprint::enum(), so the database ENUM column and the PHP enum are
 * generated from ONE list and cannot drift.
 *
 * This is the whole reason these enums exist in Stage 0 even though no
 * business logic consumes them yet: in backend/ the vocabulary was duplicated
 * between a .sql ENUM and hand-written in_array() checks scattered across
 * middleware and services, which is exactly how 'teacher'/'staff' ended up
 * documented as valid roles for months while the code rejected them.
 */
trait HasValues
{
    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
