<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Http\Requests\QuestionBank\DeleteQuestionGroupRequest;
use App\Http\Requests\QuestionBank\DeleteQuestionRequest;
use App\Http\Requests\QuestionBank\ListQuestionsRequest;
use App\Http\Requests\QuestionBank\StoreQuestionGroupRequest;
use App\Http\Requests\QuestionBank\StoreQuestionRequest;
use App\Http\Requests\QuestionBank\UpdateQuestionGroupRequest;
use App\Http\Requests\QuestionBank\UpdateQuestionRequest;
use App\Http\Resources\QuestionGroupResource;
use App\Http\Resources\StaffQuestionResource;
use App\Models\Question;
use App\Models\QuestionGroup;
use App\Models\User;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The question bank — freeze-table rows 80-87. EIGHT routes, all
 * `auth`,`active`,`role:admin,instructor`.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ONE OWNERSHIP CHAIN IN THIS APPLICATION THAT NEVER TOUCHES A        ║
 * ║ COURSE. `question_groups.teacher_id` is a direct FK to                   ║
 * ║ `teachers.user_id`, so there is no section, no content item and no       ║
 * ║ course anywhere in the resolution — which is exactly why                 ║
 * ║ App\Policies\QuestionGroupPolicy is the one policy that does NOT use     ║
 * ║ App\Policies\Concerns\ResolvesCourseOwnership.                           ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Every refusal (404 group, 404 question, 403 'You do not own this question
 * group') is raised by the Form Requests BEFORE these methods are entered; see
 * App\Support\AssessmentAccess. The methods below therefore contain no
 * authorization logic at all, which is the property that makes them auditable.
 *
 * ┌── THE RESPONSE ENVELOPES ARE NESTED, AND THAT IS THE CONTRACT. ──────────┐
 * │   GET  /question-groups              -> data: { groups:    [ … ] }       │
 * │   POST /question-groups              -> data: { group:     { … } }  201  │
 * │   PATCH/DELETE …                     -> data: { group: … } / {}          │
 * │   GET  …/{groupId}/questions         -> data: { questions: [ … ] }       │
 * │   POST …/{groupId}/questions         -> data: { question:  { … } }  201  │
 * │ backend/QuestionBankController wraps every payload in that extra key. A  │
 * │ bare array would be a different body for the same request.               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── THE ARABIC/ENGLISH SPLIT IS PORTED. DO NOT LEVEL IT. ──────────────────┐
 * │ Success messages and field-validation text are ARABIC (they render as    │
 * │ toasts and inline errors); ownership and not-found refusals are ENGLISH  │
 * │ (App\Support\AssessmentAccess). Both halves reach a consumer, so both    │
 * │ are contract. Note also that the two GROUP verbs use the GENERIC strings │
 * │ 'تم التحديث بنجاح' / 'تم الحذف بنجاح' while the QUESTION verbs are       │
 * │ specific ('تم تحديث السؤال بنجاح' / 'تم حذف السؤال بنجاح') — an          │
 * │ inconsistency in backend/QuestionBankController that is reproduced        │
 * │ literally rather than harmonised.                                        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NOTHING HERE TOUCHES `exam_attempts` OR `assignment_submissions`. Stage 6 /
 * Session A registers no write route on either table — see routes/api.php.
 */
final class QuestionBankController extends Controller
{
    /**
     * GET /api/v1/question-groups — row 80. A LIST SCOPE, not an ability.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ INSTRUCTORS SEE THEIR OWN BANKS; ADMINS SEE ALL. THE BRANCH LIVES    ║
     * ║ HERE AND NOT INSIDE THE SCOPE — the identical argument                ║
     * ║ GroupController::index() makes for row 74, and the freeze table's     ║
     * ║ conclusion for all five list routes.                                  ║
     * ║                                                                      ║
     * ║ QuestionGroup::scopeOwnedBy() expresses ONE predicate. Teaching it to ║
     * ║ return EVERYTHING for one role would hide a privilege rule inside a   ║
     * ║ query builder, where nobody auditing authorization would look. And an ║
     * ║ ABILITY cannot do this job at all: `viewAny` is yes/no about an       ║
     * ║ ACTION and cannot say "these rows but not those".                     ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * ORDERED `created_at DESC`, from backend/QuestionGroupRepository — both
     * listByTeacher() and listAll() say so. `id` is the tie-break because
     * `created_at` has second precision and two banks made in the same second
     * would otherwise return in an unstable order; the key is a UUIDv7, i.e.
     * creation order, so the tie-break agrees with the sort rather than fighting
     * it.
     *
     * NO PAGINATION, ported: the legacy queries have no LIMIT and the bank list
     * is a per-teacher sidebar, not a catalogue.
     */
    public function indexGroups(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = QuestionGroup::query()->orderByDesc('created_at')->orderByDesc('id');

        if ($user->role !== UserRole::Admin) {
            $query->ownedBy($user);
        }

        return ApiResponse::success([
            'groups' => QuestionGroupResource::collection($query->get()),
        ]);
    }

    /**
     * POST /api/v1/question-groups -> 201. Row 81.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ `teacher_id` COMES FROM THE SESSION AND FROM NOWHERE ELSE.           ║
     * ║                                                                      ║
     * ║ It is set with setAttribute on a model that deliberately omits the    ║
     * ║ column from $fillable, so there is no code path — not `create()`, not ║
     * ║ `fill()`, not a future `update($request->validated())` — on which a   ║
     * ║ request key could reach it. Without that, a caller could file a bank  ║
     * ║ under another teacher's id and then read it back through their own    ║
     * ║ listing, because the list scope trusts exactly this column.           ║
     * ║                                                                      ║
     * ║ AN ADMIN CREATING A BANK BECOMES ITS OWNER, matching backend/         ║
     * ║ (`createGroup($this->uid(), …)` with no role branch). The FK is       ║
     * ║ `teachers.user_id`, so an admin WITHOUT a `teachers` row gets a clean ║
     * ║ 1452 rather than a silent mis-file — an edge the legacy code has too, ║
     * ║ and one no admin console exercises. Recorded, not "fixed".            ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * `created_at` is left to the column's own DEFAULT CURRENT_TIMESTAMP,
     * exactly as backend/'s `INSERT … NOW()` did; `$timestamps = false` on the
     * model is what stops Eloquent writing an `updated_at` that does not exist.
     */
    public function storeGroup(StoreQuestionGroupRequest $request): JsonResponse
    {
        $group = new QuestionGroup();

        $group->fill([
            'title' => (string) $request->input('title'),
            'grade' => $request->input('grade'),
        ]);
        $group->setAttribute('teacher_id', $request->user()?->getKey());
        $group->save();

        return ApiResponse::created(
            ['group' => new QuestionGroupResource($group->refresh())],
            'تم إنشاء مجموعة الأسئلة بنجاح'
        );
    }

    /**
     * PATCH /api/v1/question-groups/{id}. Row 82.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE TWO FIELDS USE **DIFFERENT** EMPTY-STRING SEMANTICS, IN THE SAME ║
     * ║ REQUEST. THE FULL TABLE AND THE LEGACY SOURCE ARE IN                 ║
     * ║ App\Http\Requests\QuestionBank\UpdateQuestionGroupRequest'S BANNER — ║
     * ║ READ IT BEFORE TOUCHING EITHER LINE BELOW.                           ║
     * ║                                                                      ║
     * ║   title  `!empty()`            -> '' (and '0') KEEP the old value     ║
     * ║   grade  `array_key_exists()`  -> '' is WRITTEN, null CLEARS          ║
     * ║                                                                      ║
     * ║ Both idioms are reproduced literally below, including `!empty()`'s    ║
     * ║ '0' quirk. Replacing either with the other's shape is a silent        ║
     * ║ contract change on a live authoring form.                             ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * The legacy repository writes BOTH columns unconditionally
     * (`SET title = :title, grade = :grade`), which is why the "keep" branches
     * re-assign the existing value rather than skipping the column: the two are
     * equivalent on the wire, and stating them the same way as backend/ keeps
     * the comparison readable.
     */
    public function updateGroup(UpdateQuestionGroupRequest $request): JsonResponse
    {
        $group = $request->group();
        $data = $request->validated();

        // `!empty()`: absent, null, '' AND '0' all mean "keep the old title".
        if (!empty($data['title'])) {
            $group->setAttribute('title', $data['title']);
        }

        // `array_key_exists()`: '' is written, an explicit null clears.
        if (array_key_exists('grade', $data)) {
            $group->setAttribute('grade', $data['grade']);
        }

        $group->save();

        return ApiResponse::success(
            ['group' => new QuestionGroupResource($group->refresh())],
            'تم التحديث بنجاح'
        );
    }

    /**
     * DELETE /api/v1/question-groups/{id}. Row 83.
     *
     * HARD delete. `fk_questions_group` is ON DELETE CASCADE so the bank's
     * questions go with it, and `fk_exams_question_group` is ON DELETE SET NULL
     * so every exam pointing at it is silently detached. The second cascade is
     * open item 6.3 — a 409-style guard was CONSIDERED AND DELIBERATELY NOT
     * BUILT this session; see docs/open-decisions.md and
     * DeleteQuestionGroupRequest's banner.
     *
     * The generic 'تم الحذف بنجاح', not a bank-specific string — backend/'s
     * wording, and deliberately different from the question delete's.
     */
    public function destroyGroup(DeleteQuestionGroupRequest $request): JsonResponse
    {
        $request->group()->delete();

        return ApiResponse::success(null, 'تم الحذف بنجاح');
    }

    /**
     * GET /api/v1/question-groups/{groupId}/questions. Row 84.
     *
     * SERVES StaffQuestionResource — the ONLY class in the application that
     * emits `correct_answer`. Every caller that reaches this line is the bank's
     * owner or an admin (ListQuestionsRequest::authorize()), i.e. someone who
     * authored the key. The student-facing paper is row 90, a Session B route,
     * served by QuestionResource, which has no code path capable of reading the
     * column.
     *
     * ORDER BY `id ASC`, from QuestionRepository::listByGroup(). It lives on
     * QuestionGroup::questions() so the one ordering cannot drift between call
     * sites; `questions` has no order_index and no created_at, so the UUIDv7
     * key is the only stable ordering there is.
     */
    public function indexQuestions(ListQuestionsRequest $request): JsonResponse
    {
        return ApiResponse::success([
            'questions' => StaffQuestionResource::collection($request->group()->questions()->get()),
        ]);
    }

    /**
     * POST /api/v1/question-groups/{groupId}/questions -> 201. Row 85.
     *
     * The three WRITE-TIME defaults are backend/'s exactly — `type ?? 'mcq'`,
     * `points ?? 1`, everything else `?? null` — and they live here rather than
     * in the Form Request so that an explicit null falls back the same way an
     * absent key does, which is what the `??` chains actually do.
     *
     * `question_group_id` is set from the ROUTE SEGMENT that was authorised,
     * never from the body, and the column is not fillable — so the row lands in
     * the bank whose ownership was just proven and in no other.
     */
    public function storeQuestion(StoreQuestionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $question = new Question();

        $question->fill([
            'title' => $data['title'] ?? null,
            'body' => (string) $data['body'],
            'type' => $data['type'] ?? QuestionType::Mcq->value,
            'options' => $data['options'] ?? null,
            'choice_format' => $data['choice_format'] ?? '1234',
            'image_url' => $data['image_url'] ?? null,
            'correct_answer' => $data['correct_answer'] ?? null,
            'points' => $data['points'] ?? 1,
        ]);
        $question->setAttribute('question_group_id', $request->group()->getKey());
        $question->save();

        return ApiResponse::created(
            ['question' => new StaffQuestionResource($question->refresh())],
            'تم إضافة السؤال بنجاح'
        );
    }

    /**
     * PATCH /api/v1/questions/{id}. Row 86.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ TWO GUARDS, SPLIT PER FIELD, PORTED FROM QuestionRepository::update():║
     * ║                                                                      ║
     * ║   isset()             body, type, points     null = LEAVE IT          ║
     * ║   array_key_exists()  options, image_url,    null = **CLEAR IT**      ║
     * ║                       correct_answer                                  ║
     * ║                                                                      ║
     * ║ The second group is the ONLY way to erase an answer key, a choice     ║
     * ║ list or an image through this API. Collapsing the two idioms into one ║
     * ║ deletes that capability silently. The full semantics table is in      ║
     * ║ App\Http\Requests\QuestionBank\UpdateQuestionRequest's banner.        ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * backend/ returns early when NO field matched (`if (empty($fields))
     * return;`). Here `save()` on an unchanged model is already a no-op that
     * issues no UPDATE, so the guard is structural rather than written out.
     */
    public function updateQuestion(UpdateQuestionRequest $request): JsonResponse
    {
        $question = $request->question();
        $data = $request->validated();

        // isset(): an explicit null means "leave this column alone".
        foreach (['title', 'body', 'type', 'choice_format', 'points'] as $field) {
            if (($data[$field] ?? null) !== null) {
                $question->setAttribute($field, $data[$field]);
            }
        }

        // array_key_exists(): an explicit null CLEARS the column.
        foreach (['options', 'image_url', 'correct_answer'] as $field) {
            if (array_key_exists($field, $data)) {
                $question->setAttribute($field, $data[$field]);
            }
        }

        $question->save();

        return ApiResponse::success(
            ['question' => new StaffQuestionResource($question->refresh())],
            'تم تحديث السؤال بنجاح'
        );
    }

    /** DELETE /api/v1/questions/{id}. Row 87. Hard delete, no audit. */
    public function destroyQuestion(DeleteQuestionRequest $request): JsonResponse
    {
        $request->question()->delete();

        return ApiResponse::success(null, 'تم حذف السؤال بنجاح');
    }
}
