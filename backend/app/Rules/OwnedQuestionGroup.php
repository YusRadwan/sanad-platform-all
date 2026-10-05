<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\UserRole;
use App\Models\QuestionGroup;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ APPROVED DECISION #2 — THE UNOWNED-QUESTION-GROUP LEAK, CLOSED.        ║
 * ║                                                                          ║
 * ║ THE LEAK, CONCRETELY: teacher B creates an exam under THEIR OWN course   ║
 * ║ and sets `question_group_id` to a bank belonging to teacher A. Nothing   ║
 * ║ rejected it — `fk_exams_question_group` only requires the row to EXIST,  ║
 * ║ never that it be yours, and                                              ║
 * ║ backend/ContentItemService::saveTypeDetail() passes the value straight   ║
 * ║ through. Teacher B then calls GET /exams/{their own exam}/questions,     ║
 * ║ which resolves the bank server-side and returns teacher A's questions —  ║
 * ║ **including `correct_answer`**, because ExamService::questionsFor()      ║
 * ║ keeps the answer key for course STAFF and teacher B is legitimately      ║
 * ║ staff on their own course.                                              ║
 * ║                                                                          ║
 * ║ So a one-field write on an owned resource reads a foreign tenant's       ║
 * ║ answer keys. It is a genuine cross-tenant data leak, not a hardening     ║
 * ║ opportunity, and it is closed at the ONLY point the id is accepted.     ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── WHY A RULE HERE AND NOT A CHECK INSIDE PersistExamDetail. ─────────────┐
 * │ The persister runs INSIDE the controller's DB::transaction(), after the  │
 * │ parent `content_items` row has already been written. A refusal there is  │
 * │ a rollback plus an exception shaped like a 500, where the contract owes  │
 * │ a 422 with a field-keyed message. Validation runs before any write, so   │
 * │ the request is rejected with nothing to undo — and Stage 5B's persister  │
 * │ is left exactly as it was, which was the instruction.                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── WHY `$implicit = true`. MEASURED MECHANISM, SAME AS App\Rules\        ─┐
 * │ SafeUrlValue's (open decision 5.10).                                     │
 * │ Laravel's Validator::presentOrRuleIsImplicit() SKIPS every non-implicit  │
 * │ rule for a blank string:                                                 │
 * │     if (is_string($value) && trim($value) === '') {                      │
 * │         return $this->isImplicit($rule);                                 │
 * │     }                                                                    │
 * │ Without the flag, `question_group_id: ''` would sail past this rule —    │
 * │ and `''` is preserved on this route family                               │
 * │ (AppServiceProvider::preserveEmptyStringsOnClearableWrites lists         │
 * │ `api/v1/content-items/*`), so it would reach PersistExamDetail, survive  │
 * │ its `!== null` array_filter, and hit a CHAR(36) FK: SQLSTATE 1452, a     │
 * │ 500. The flag turns that into the 422 below. Same class of fix as        │
 * │ decision 5.2.                                                            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── A NON-EXISTENT ID ALSO 422s, AND THAT IS ON PURPOSE. ──────────────────┐
 * │ There is deliberately NO `exists:question_groups,id` rule alongside this │
 * │ one, and there must not be: an existence rule is a POSITIVE ORACLE — it  │
 * │ distinguishes "that bank is not yours" from "no such bank", which lets a │
 * │ caller enumerate other teachers' bank ids one guess at a time. This rule │
 * │ answers the SAME message for both cases, so a probe learns nothing. The  │
 * │ side effect is that a bad id becomes 422 instead of backend/'s raw FK    │
 * │ 500 — recorded in docs/open-decisions.md, since no previously-successful │
 * │ request changes.                                                         │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ADMINS ARE EXEMPT, matching every other ownership rule in this application
 * (Gate::before). The exemption is written out here rather than routed through
 * the Gate because there is no MODEL to authorise against when the id names
 * nothing — and consulting a policy on a hypothetical row is how the oracle
 * above creeps back in.
 */
final class OwnedQuestionGroup implements ValidationRule
{
    /** See the banner: '' must not skip this rule. */
    public bool $implicit = true;

    /** The literal 422 message. Arabic, because it renders in the authoring form. */
    public const MESSAGE = 'مجموعة الأسئلة المحددة لا تخصك';

    public function __construct(private readonly ?User $actor)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Absent / explicit null: "leave the link alone" (or "no bank"), which
        // is what PersistExamDetail's `?? null` + array_filter already mean.
        // Nothing to own, nothing to check.
        if ($value === null) {
            return;
        }

        // FAIL CLOSED on a missing identity. Unreachable through the routes
        // that use this rule (all are `auth` + `role:admin,instructor`), and
        // the type is the belt to the middleware's braces.
        if ($this->actor === null) {
            $fail(self::MESSAGE);

            return;
        }

        if ($this->actor->role === UserRole::Admin) {
            return;
        }

        $owned = QuestionGroup::query()
            ->whereKey((string) $value)
            ->where('teacher_id', $this->actor->getKey())
            ->exists();

        if (!$owned) {
            $fail(self::MESSAGE);
        }
    }
}
