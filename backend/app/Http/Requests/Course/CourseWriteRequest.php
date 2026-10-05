<?php

declare(strict_types=1);

namespace App\Http\Requests\Course;

use App\Enums\UserRole;
use App\Exceptions\ForbiddenException;
use App\Http\Requests\ApiFormRequest;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Course;
use App\Rules\AssignedToTeacher;
use App\Rules\DiscountNotAbovePrice;
use Illuminate\Validation\Rule;

/**
 * Shared shape rules for POST /courses and PUT /courses/{id}.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS CLASS VALIDATES SHAPE AND NORMALISES KEY NAMES. IT DOES **NOT**     ║
 * ║ DECIDE WHAT GETS WRITTEN — App\Actions\Course\WriteCourseAttributes does,║
 * ║ field by field, next to the table that documents the semantics.          ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Same division of labour as Stage 4's UpdateTeacherRequest, and for the same
 * reason: Course update has TWO OPPOSITE absent/empty/value conventions inside
 * ONE request body, and encoding that in rules() is not possible without
 * conditional rule construction that would then be the real business logic
 * hiding in a validator.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE FIELD-SEMANTICS TABLE THIS SESSION EXISTS TO PRESERVE               │
 * │ (proven end to end in tests/Feature/Course/CourseFieldSemanticsTest.php) │
 * │                                                                          │
 * │  field           absent      ''                       value              │
 * │  ───────────────  ─────────  ───────────────────────  ─────              │
 * │  title            untouched  **422** (min:3)          update             │
 * │  thumbnail_url    untouched  **CLEAR**                update             │
 * │  description      untouched  **CLEAR**                update             │
 * │  discount_price   untouched  **IGNORED — not "clear"** update            │
 * │  price            untouched  **IGNORED — not "clear"** update            │
 * │  is_published     untouched  n/a (presence-based)     update, incl. false│
 * │                                                                          │
 * │ ══ THE `discount_price` ROW IS THE INVERTED ONE. READ IT TWICE. ══       │
 * │                                                                          │
 * │ In the SAME request body, `description: ''` clears the column while      │
 * │ `discount_price: ''` is treated as if the key had never been sent. That  │
 * │ asymmetry looks like a transcription error and is not. It is             │
 * │ MEASURED — see below — and it is implemented HERE, by REMOVING the key   │
 * │ from the request in prepareForValidation(), so that every downstream     │
 * │ layer (rules, the Action's has() checks, the Rule's baseline) agrees by  │
 * │ construction instead of by three separate people remembering.            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ WHY '' IS "IGNORED" AND NOT "CLEAR" FOR THE TWO MONEY COLUMNS.          ║
 * ║ MEASURED AGAINST REAL MySQL, NOT INFERRED FROM backend/'s SOURCE.       ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * backend/CourseService::update() is INTERNALLY INCONSISTENT about this, and
 * reading only one half of it produces the wrong answer:
 *
 *   * assertValidPricing() (~lines 272-298) tests
 *     `$data['x'] !== null && $data['x'] !== ''` FOUR times — it treats '' as
 *     "not sent" everywhere, deliberately and explicitly.
 *
 *   * the write path immediately below it does
 *     `array_filter(['discount_price' => $data['discount_price'] ?? null, …],
 *      fn ($v) => $v !== null)`
 *     which drops only NULL — so '' SURVIVES the filter and reaches the
 *     UPDATE.
 *
 * What actually happens when it reaches MySQL was probed directly against this
 * project's server (sql_mode includes STRICT_TRANS_TABLES):
 *
 *     UPDATE p SET discount_price = '' WHERE id = 1
 *     → SQLSTATE[HY000] 1366 Incorrect decimal value: '' for column
 *       'discount_price'
 *
 * So backend/'s effective behaviour for `discount_price: ''` is **a 500**, not
 * a clear and not an update. There is no contract to preserve, only a crash to
 * remove, and the validator's own reading of '' — "not sent" — is the half of
 * the file that was written on purpose. Stage 5 adopts it for BOTH money
 * columns. Recorded as decision 5.1 in docs/open-decisions.md.
 *
 * `description`/`thumbnail_url` are TEXT/VARCHAR, so '' is a legal value there
 * and the very same array_filter genuinely does write it. Hence the asymmetry:
 * it is a property of the COLUMN TYPES, not of anybody's preference.
 *
 * ┌── ALIAS NORMALISATION: FOUR KEY NAMES, TWO FIELDS. ──────────────────────┐
 * │ useCourses.ts sends `grade_name`/`subject_name`; CourseManagement.tsx's  │
 * │ own Course object — and backend/CourseService::present() — use the       │
 * │ shorter `grade`/`subject`. backend/'s submittedGrade() accepts BOTH      │
 * │ (`$data['grade_name'] ?? $data['grade'] ?? ''`), so a caller posting     │
 * │ either convention hits the same rule.                                    │
 * │                                                                          │
 * │ Both are collapsed to `grade`/`subject` here — the SHORT name, chosen    │
 * │ deliberately — because a failed AssignedToTeacher check must report      │
 * │ under the key backend/ used:                                             │
 * │   ValidationException(['grade' => ['غير مسموح لك …']])                   │
 * │ Laravel reports a rule failure under the ATTRIBUTE NAME, so canonicalising│
 * │ to `grade_name` would silently rename an error key the course form reads.│
 * └──────────────────────────────────────────────────────────────────────────┘
 */
abstract class CourseWriteRequest extends ApiFormRequest
{
    /**
     * Resolved at most once. rules() is invoked twice per request — once by
     * getValidatorInstance() and once by ApiFormRequest::attributes(), which
     * builds its identity map from the rule keys — and the profile lookup must
     * not become two queries (nor two chances to throw).
     */
    private ?Teacher $actingTeacher = null;

    private bool $actingTeacherResolved = false;

    /**
     * The two money columns whose '' means "not sent". See the class docblock.
     */
    private const MONEY_KEYS = ['price', 'discount_price'];

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ RUNS BEFORE authorize() AND BEFORE rules(). PURE INPUT RESHAPING.    │
     * │ No authorization, no database, no business rules — those have their  │
     * │ own layers and their own ordering guarantees.                        │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * replace(), not merge(): merge() can only ADD keys, and half the work
     * here is REMOVING them. replace() writes through Request::getInputSource(),
     * which is JSON-aware — the frontend posts application/json, so a
     * ParameterBag-level edit of `$this->request` would silently do nothing.
     */
    protected function prepareForValidation(): void
    {
        $data = $this->all();

        // ── the four-names-two-fields collapse ────────────────────────────
        foreach (['grade' => 'grade_name', 'subject' => 'subject_name'] as $short => $long) {
            if (array_key_exists($long, $data) || array_key_exists($short, $data)) {
                $data[$short] = trim((string) ($data[$long] ?? $data[$short] ?? ''));
            }

            unset($data[$long]);
        }

        // ── '' on a money column means "not sent" — so make it not sent ───
        foreach (self::MONEY_KEYS as $key) {
            if (array_key_exists($key, $data)
                && ($data[$key] === null || trim((string) $data[$key]) === '')
            ) {
                unset($data[$key]);
            }
        }

        $this->replace($data);
    }

    /**
     * The rules every course write shares. Subclasses add `title` (whose
     * required-ness is the only real difference between create and update) and,
     * for create, `teacher_id`.
     *
     * @return array<string, mixed>
     */
    protected function sharedRules(): array
    {
        $rules = [
            // '' CLEARS these two — so `nullable` is mandatory (a null must be
            // legal at the validator so the Action can decide it means
            // "untouched"), and no `min` may ever be added.
            'description' => ['sometimes', 'nullable', 'string'],
            'thumbnail_url' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Blank values were removed above, so `nullable` would be dead
            // weight and `numeric` is safe to state unconditionally.
            'price' => ['sometimes', 'numeric', 'gte:0'],
            'discount_price' => [
                'sometimes',
                'numeric',
                'gte:0',
                new DiscountNotAbovePrice(
                    $this->baselinePrice(),
                    $this->has('price') ? (string) $this->input('price') : null,
                ),
            ],

            'grade' => ['sometimes', 'nullable', 'string', 'max:190'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:190'],

            // PRESENCE-based, never truthiness-based: `is_published: false` is
            // a real instruction (unpublish) and must reach the Action.
            'is_published' => ['sometimes', 'boolean'],

            // Center System (decision 10.24) — course scope. Same
            // presence-based treatment as is_published.
            'visibility' => ['sometimes', Rule::in([
                Course::VISIBILITY_PLATFORM,
                Course::VISIBILITY_CENTER,
                Course::VISIBILITY_BOTH,
            ])],
        ];

        // ── PART 8: an instructor's own assigned lists ────────────────────
        // NOT attached for an admin: backend/ exempts them wholesale, and
        // re-deriving that exemption inside the Rule would put a second copy
        // of the admin bypass in a class Gate::before never touches.
        $teacher = $this->resolveActingTeacher();

        if ($teacher !== null) {
            $rules['grade'][] = new AssignedToTeacher(
                $teacher,
                'grades',
                AssignedToTeacher::GRADE_MESSAGE,
            );
            $rules['subject'][] = new AssignedToTeacher(
                $teacher,
                'subjects',
                AssignedToTeacher::SUBJECT_MESSAGE,
            );
        }

        return $rules;
    }

    /**
     * The instructor whose grade/subject lists constrain this write — or null
     * when there is nothing to constrain (the caller is an admin).
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE MISSING-PROFILE CASE IS A **403**, NOT A 422. IT IS THROWN HERE. ║
     * ║                                                                      ║
     * ║ backend/assertGradeSubjectAllowed() looks the profile up FIRST and    ║
     * ║ throws ForbiddenException('لا يوجد ملف مدرس لهذا الحساب') when it is  ║
     * ║ absent — a different STATUS from the 422 an unassigned grade          ║
     * ║ produces, and the frontend branches on the difference. Folding it     ║
     * ║ into AssignedToTeacher's normal failure path would quietly demote a   ║
     * ║ 403 to a 422.                                                         ║
     * ║                                                                      ║
     * ║ It cannot be a rule at all: a rule only runs when its FIELD is        ║
     * ║ present, and this refusal must fire even for a course carrying no     ║
     * ║ grade and no subject — which is exactly what backend/ does, since its ║
     * ║ lookup precedes both checks.                                          ║
     * ║                                                                      ║
     * ║ Note this is reachable in practice only through a corrupted account:  ║
     * ║ `courses.teacher_id` is an FK into `teachers.user_id`, so an          ║
     * ║ instructor with no profile row could not own a course even if the     ║
     * ║ write were allowed. The check turns that FK violation (a 500 with a   ║
     * ║ raw constraint name) into the diagnosis backend/ already gave.        ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    protected function resolveActingTeacher(): ?Teacher
    {
        if ($this->actingTeacherResolved) {
            return $this->actingTeacher;
        }

        $this->actingTeacherResolved = true;

        /** @var User|null $user */
        $user = $this->user();

        if ($user === null || $user->role === UserRole::Admin) {
            return $this->actingTeacher = null;
        }

        $teacher = Teacher::query()->whereKey($user->getKey())->first();

        if ($teacher === null) {
            throw new ForbiddenException('لا يوجد ملف مدرس لهذا الحساب');
        }

        return $this->actingTeacher = $teacher;
    }

    /**
     * The price a submitted `discount_price` is measured against when the same
     * request carries no `price`. Create uses the column default; update
     * overrides this with the STORED value.
     */
    protected function baselinePrice(): float
    {
        return 0.0;
    }

    /**
     * ApiFormRequest supplies the Arabic templates Stages 0-4 needed. Two are
     * new here; both reproduce backend/Http/Requests/Validator.php's wording:
     *
     *   gte      "الحقل {field} يجب ألا يقل عن {param}"   (~line 99)
     *   boolean  the numeric/date range checks Part 7.2 added
     *
     * ┌── `gte` MUST BE DECLARED AS AN **ARRAY**, KEYED BY ATTRIBUTE TYPE. ──┐
     * │ Gte is one of Laravel's `$sizeRules`, and                            │
     * │ Validator::getInlineMessage() only consults an inline entry for a    │
     * │ size rule THROUGH its type sub-key:                                  │
     * │                                                                      │
     * │   is_array($inlineEntry) && in_array($rule, $this->sizeRules)        │
     * │       ? $inlineEntry[$this->getAttributeType($attribute)] ?? null    │
     * │       : $inlineEntry                                                 │
     * │                                                                      │
     * │ A flat `'gte' => '…'` string IS returned (the ternary's else branch) │
     * │ but a dotted `'gte.numeric' => '…'` key is NEVER matched — the       │
     * │ lookup keys are "{$attribute}.{$rule}", "{$rule}", "{$attribute}",   │
     * │ so `gte.numeric` would only fire for a field literally named `gte`.  │
     * │ The array form is the one that survives a future price rule changing │
     * │ type. Verified against vendor/…/Concerns/FormatsMessages.php.        │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return parent::messages() + [
            'gte' => [
                'numeric' => 'الحقل :attribute يجب ألا يقل عن :value',
                'string' => 'الحقل :attribute يجب ألا يقل عن :value',
            ],
            'boolean' => 'الحقل :attribute يجب أن يكون قيمة منطقية',
        ];
    }
}
