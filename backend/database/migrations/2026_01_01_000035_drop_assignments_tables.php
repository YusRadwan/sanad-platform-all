<?php

declare(strict_types=1);

use App\Enums\ContentItemType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE ASSIGNMENTS FEATURE IS REMOVED, PERMANENTLY AND WITH ITS DATA.
 *
 * Order matters and is not cosmetic: `fk_assignment_submissions_assignment`
 * points at `assignments`, so the CHILD table goes first. Dropping the parent
 * while the child still references it is an errno 3730.
 *
 * Two other clean-ups ride along, both required for the schema to be coherent
 * afterwards:
 *
 *   1. `content_items` rows with `type = 'assignment'` are DELETED. Their
 *      detail row is gone with the `assignments` table (the cascade came from
 *      `fk_assignments_content_item`, which the drop removes), so the parent
 *      would otherwise survive as a detail-less item of a type the application
 *      no longer knows how to render — and App\Models\ContentItem::
 *      relationNameFor() is an EXHAUSTIVE match that would throw
 *      UnhandledMatchError on the cast value.
 *
 *   2. The `content_items.type` ENUM is narrowed to the remaining five cases.
 *      A fresh database already gets the narrow enum (migration 000014 builds
 *      the column from ContentItemType::values()), so on a `RefreshDatabase`
 *      run this ALTER is a no-op restatement of what is already there — it is
 *      written anyway so an EXISTING database converges on the same schema.
 *
 * There is deliberately NO down(): the data is gone, so a reversal would
 * recreate two empty tables and pretend the feature is back.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('content_items')->where('type', 'assignment')->delete();

        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignments');

        $values = implode(
            ',',
            array_map(
                static fn (string $value): string => "'".$value."'",
                ContentItemType::values()
            )
        );

        DB::statement("ALTER TABLE `content_items` MODIFY `type` ENUM({$values}) NOT NULL");
    }

    public function down(): void
    {
        // Irreversible by design — see the class docblock.
    }
};
