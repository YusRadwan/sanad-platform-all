<?php

declare(strict_types=1);

namespace App\Http\Requests\Course;

use App\Enums\UserRole;
use App\Models\User;

/**
 * POST /api/v1/courses — freeze-table row 12.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ `title` IS THE ONLY FIELD THAT DIFFERS FROM AN UPDATE, AND IT IS THE     │
 * │ ONE backend/ HAD TO ADD A VALIDATOR FOR AT ALL.                         │
 * │                                                                          │
 * │ Quoting CourseService::create()'s own comment: `title` is NOT NULL on    │
 * │ `courses` and CourseRepository::create read `$data['title']`             │
 * │ unconditionally, so a POST without one died on an "Undefined array key"  │
 * │ ErrorException — a 500 carrying a full server stack trace (absolute      │
 * │ source paths included) back to the caller. `required|string|min:3` is    │
 * │ the fix, ported verbatim.                                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── max:190, NOT backend/'s max:255. DELIBERATE, AND IT REMOVES A 500. ────┐
 * │ `courses.title` is VARCHAR(**190**) (migration 000011). backend/ capped  │
 * │ validation at 255, so a 200-character title passed the service and then  │
 * │ hit MySQL under STRICT_TRANS_TABLES:                                     │
 * │   SQLSTATE[22001] 1406 Data too long for column 'title'                  │
 * │ — a 500 where a 422 was owed. Matching the rule to the column turns that │
 * │ into an ordinary validation error and changes NOTHING about any request  │
 * │ that previously succeeded, since no title over 190 chars was ever        │
 * │ storable. Recorded as decision 5.2 in docs/open-decisions.md.            │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class StoreCourseRequest extends CourseWriteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = $this->sharedRules();

        $rules['title'] = ['required', 'string', 'min:3', 'max:190'];

        /*
         * ┌──────────────────────────────────────────────────────────────────┐
         * │ `teacher_id` IS ACCEPTED FROM AN ADMIN AND FROM NOBODY ELSE.     │
         * │                                                                  │
         * │ backend/CourseService::create():                                 │
         * │   $teacherId = $role === 'admin' ? ($data['teacher_id'] ?? $userId)│
         * │                                  : $userId;                      │
         * │                                                                  │
         * │ The instructor branch does not read the request AT ALL — the id  │
         * │ is forced from the session — which is what makes it impossible   │
         * │ for one instructor to file a course under another's name. That   │
         * │ property is preserved by CONSTRUCTION in                          │
         * │ App\Http\Controllers\CourseController::store(), which only ever  │
         * │ consults this key for an admin. Declaring the rule conditionally │
         * │ here is belt and braces: for a non-admin the key is not even     │
         * │ validated, so it cannot arrive in validated() and tempt a future │
         * │ edit into trusting it.                                           │
         * │                                                                  │
         * │ `exists` on teachers.user_id, not on users.id: courses.teacher_id │
         * │ is an FK into the PROFILE table, and an admin naming a student's │
         * │ user id must get a 422, not an FK-violation 500.                  │
         * └──────────────────────────────────────────────────────────────────┘
         */
        /** @var User|null $user */
        $user = $this->user();

        if ($user !== null && $user->role === UserRole::Admin) {
            $rules['teacher_id'] = ['sometimes', 'string', 'exists:teachers,user_id'];
        }

        return $rules;
    }
}
