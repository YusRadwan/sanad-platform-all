<?php

declare(strict_types=1);

namespace App\Http\Requests\ContentItem;

use App\Http\Requests\ApiFormRequest;
use App\Models\CourseGroup;
use App\Support\ContentItemAccess;

/**
 * GET /api/v1/groups/{groupId}/content-items — freeze-table row 78.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ONE CONTENT-ITEM READ THAT IS **NOT** PUBLIC — NOW OR EVER.         ║
 * ║                                                                          ║
 * ║ Rows 20 and 21 (`GET /sections/{id}/content-items`, `GET               ║
 * ║ /content-items/{id}`) ARE public in the final contract and are Session   ║
 * ║ C's to open. This one is not: backend/ mounts it inside the              ║
 * ║ AuthMiddleware + RoleMiddleware('admin','instructor') block and its      ║
 * ║ service method calls requireGroupAccess() unconditionally, with no       ║
 * ║ anonymous branch anywhere. It is the instructor's own working set.       ║
 * ║                                                                          ║
 * ║ It is therefore the only read this session registers at all, and it      ║
 * ║ still sits behind `auth` + `role:admin,instructor` like the five writes. ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * The ability is CourseGroupPolicy::view() — added in this session for exactly
 * this row; read its docblock for why it is a separate method from manage()
 * despite an identical body today. The refusal is 403 'You do not own this
 * group', the same string the create route uses, because backend/'s
 * listByGroup() and createForGroup() both go through requireGroupAccess().
 */
final class ListGroupContentItemsRequest extends ApiFormRequest
{
    private ?CourseGroup $group = null;

    public function authorize(): bool
    {
        ContentItemAccess::assertGroupViewable($this->group());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function group(): CourseGroup
    {
        return $this->group ??= ContentItemAccess::findGroupOrFail((string) $this->route('groupId'));
    }
}
