<?php

declare(strict_types=1);

namespace App\Http\Requests\Section;

use App\Exceptions\NotFoundException;
use App\Http\Requests\ApiFormRequest;
use App\Models\Section;
use App\Support\CourseAccess;

/**
 * DELETE /api/v1/sections/{id} — freeze-table row 19.
 *
 * A Form Request with NO rules exists here for one reason: it is the layer
 * that runs authorize() BEFORE the controller body, giving the delete the same
 * refusal ladder as the update (404 section → 404 course → 403 owner) without
 * re-typing it in a controller method.
 *
 * ┌── THIS DELETE IS **HARD**, AND IT IS NOT AUDITED. BOTH ARE PORTED. ──────┐
 * │ `sections` has no `deleted_at`, so the row is gone and `content_items`   │
 * │ cascade with it (fk_content_items_section, ON DELETE CASCADE).           │
 * │                                                                          │
 * │ And unlike a COURSE delete — which backend/ records through              │
 * │ Logger::critical_operation('Course deleted') — a section delete writes   │
 * │ no audit line at all. That asymmetry is deliberate and preserved: the    │
 * │ course is the tenant-visible asset, a section is an editing operation    │
 * │ inside one. Adding a log here would look like an improvement and would   │
 * │ silently change what an auditor reading the security channel sees.       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO ADMIN RE-AUTHENTICATION either, unlike Stage 4's three deletes: decision
 * #1 of that stage covered `users`/`teachers`/`students`, whose deletion is
 * irreversible account loss. This one is ordinary content editing.
 */
final class DeleteSectionRequest extends ApiFormRequest
{
    private ?Section $section = null;

    public function authorize(): bool
    {
        CourseAccess::assertManageable(
            CourseAccess::parentCourseOf($this->section())
        );

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function section(): Section
    {
        return $this->section ??= Section::query()
            ->whereKey((string) $this->route('id'))
            ->first()
            ?? throw new NotFoundException(CourseAccess::SECTION_NOT_FOUND);
    }
}
