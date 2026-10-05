<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\QuestionGroup;
use App\Models\User;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ IMPLEMENTED IN STAGE 6 / SESSION A. BOTH ABILITIES ARE REAL.            ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Ported shape from backend/app/Services/QuestionBankService::getOwnedGroup()
 * (~lines 96-106).
 *
 * ┌── NOTE THE ABSENCE OF `use ResolvesCourseOwnership`. ────────────────────┐
 * │ A question group belongs to a TEACHER, not to a course:                  │
 * │ `question_groups.teacher_id` is a direct FK to teachers.user_id          │
 * │ (migration 2026_01_01_000019). There is no Course anywhere in the chain, │
 * │ so importing the trait would pull in Course/Subscription and four        │
 * │ methods this policy must never call — which is exactly why the engine is │
 * │ a trait and not a base class.                                            │
 * │                                                                          │
 * │ The comparison still owes the SAME rule the trait encodes:               │
 * │   (string) $group->teacher_id === (string) $user->id                     │
 * │ explicit cast on BOTH sides, `===` never `==`. The casts are not         │
 * │ decoration: `===` on mismatched types is always false (a fail-closed but │
 * │ real denial), and `==` — the naive fix — is the classic auth-bypass      │
 * │ primitive on numeric-looking strings. See                                │
 * │ ResolvesCourseOwnership::ownsCourse() for the argument in full.          │
 * │                                                                          │
 * │ Stage 3 said: "If Stage 6 finds a THIRD direct-owner policy, that is the │
 * │ moment to extract a tiny `ComparesOwnerId` trait — not now, on two call  │
 * │ sites." STAGE 6 / SESSION A FOUND NO THIRD ONE. The count is still two   │
 * │ (this and NotificationPolicy, Stage 8), so the extraction is still NOT   │
 * │ made and the comparison is still written out once, here, where it can be │
 * │ read. Questions do NOT add a third: they delegate to this class rather   │
 * │ than comparing anything of their own — see App\Models\Question.          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ADMINS ARE ABSENT FROM THIS FILE: Gate::before has already answered for them.
 * That is what makes backend/getOwnedGroup()'s `$role !== 'admin' && …` branch
 * unnecessary here rather than merely omitted.
 */
class QuestionGroupPolicy
{
    /**
     * Create/rename/delete a question group and its questions — freeze-table
     * rows 82, 83, 85, 86 and 87.
     *
     * Rejection message: 'You do not own this question group'
     * (App\Support\AssessmentAccess::NOT_OWNER_GROUP).
     *
     * ┌── THE QUESTIONS HAVE NO POLICY OF THEIR OWN, BY DESIGN. ─────────────┐
     * │ A question is only ever reached through its group                    │
     * │ (backend/ resolves question -> question_group_id -> getOwnedGroup),  │
     * │ so the group is the SINGLE authorization boundary. Adding a          │
     * │ QuestionPolicy would create a second one that could disagree — and   │
     * │ App\Models\Question's non-fillable `question_group_id` is what makes │
     * │ the delegation sound: a question cannot be moved between banks, so   │
     * │ the group that authorised the request is the group that still owns   │
     * │ the row when the write lands.                                        │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function manage(User $user, QuestionGroup $group): bool
    {
        return (string) $group->teacher_id === (string) $user->id;
    }

    /**
     * READ the bank's questions — freeze-table row 84.
     *
     * ┌── A SEPARATE ABILITY WITH AN IDENTICAL BODY, AND THAT IS ON PURPOSE. ┐
     * │ backend/QuestionBankService::listQuestions() calls the very same     │
     * │ getOwnedGroup() that the four mutating methods call, so the two      │
     * │ answers are equal TODAY. They are not the same QUESTION: a read may  │
     * │ plausibly widen (a future shared/departmental bank, an admin review  │
     * │ surface), and a write may not. This is the same split                │
     * │ CourseGroupPolicy::view()/::manage() carries for row 78 and for the  │
     * │ same reason.                                                          │
     * │                                                                      │
     * │ Delegating rather than repeating the comparison keeps the string-    │
     * │ cast rule above true here by construction.                            │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function view(User $user, QuestionGroup $group): bool
    {
        return $this->manage($user, $group);
    }
}
