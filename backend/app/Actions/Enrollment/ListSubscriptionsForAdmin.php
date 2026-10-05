<?php

declare(strict_types=1);

namespace App\Actions\Enrollment;

use App\Models\RevenueTransaction;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/admin/subscriptions — freeze-table row 53.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE PAYLOAD IS A LIST OF **STUDENTS**, EACH OWNING A NESTED              ║
 * ║ `subscriptions` ARRAY. IT IS NOT A FLAT LIST OF SUBSCRIPTION ROWS.       ║
 * ║                                                                          ║
 * ║ backend/SubscriptionRepository carries a comment recording a real        ║
 * ║ production bug this shape caused: an earlier version emitted             ║
 * ║ `student.id` holding a SUBSCRIPTION id. Everything rendered, so it was   ║
 * ║ invisible — until the account-status button (which PATCHes              ║
 * ║ /admin/students/{id}) addressed a subscription id as a student id, and   ║
 * ║ until `student.subscriptions.map()` ran against a student object that    ║
 * ║ had one subscription's worth of fields instead of an array.              ║
 * ║                                                                          ║
 * ║ THIS IMPLEMENTATION CANNOT REPRODUCE THAT, BY CONSTRUCTION: the grouping ║
 * ║ key IS `users.id` (via `subscriptions.student_id`, which points at       ║
 * ║ `students.user_id`, which IS the user's id), and `id` / `student_id` are ║
 * ║ both written from that one variable. Pinned by AdminSubscriptionsTest::  ║
 * ║ test_the_top_level_id_is_the_student_never_a_subscription_id.            ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── THREE DIFFERENT VOCABULARIES FOR "ACTIVE" LIVE IN THIS ONE PAYLOAD.   ─┐
 * │                                                                          │
 * │   student.status          'active' | 'suspended'   users.is_active       │
 * │   subscription.status     'active' | 'cancelled' | 'expired'             │
 * │   subscription.isActive   boolean, == (status === 'active')              │
 * │                                                                          │
 * │ …and a FOURTH exists elsewhere in this codebase: StudentResource (Stage  │
 * │ 4) spells the very same users.is_active as 'active' | 'inactive'.        │
 * │                                                                          │
 * │ THE TOP-LEVEL `status` AND THE PER-SUBSCRIPTION `status` ARE NOT THE     │
 * │ SAME CONCEPT AND MUST NEVER BE UNIFIED. One is "can this person log in   │
 * │ at all", the other is "does this person have access to that course". A   │
 * │ suspended account with three active subscriptions and a live account     │
 * │ with three cancelled ones are both ordinary states.                      │
 * │                                                                          │
 * │ The inconsistency is inherited, not introduced; it is recorded in        │
 * │ docs/open-decisions.md (7.2) and is deliberately NOT fixed here — the    │
 * │ strings are on the wire and two frontend screens read them.              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── FILTERING IS DONE BY THE DATABASE, NOT BY PHP AFTER FETCHING ALL. ─────┐
 * │ The result set grows as (students x courses), and the admin screen's     │
 * │ export must be able to pull the whole FILTERED set rather than whatever  │
 * │ a page happened to hold. Fetching everything and filtering in a loop     │
 * │ would move the entire subscription table through PHP on every keystroke  │
 * │ of the phone-number box.                                                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── `paymentMethod` IS A CORRELATED SUBQUERY IN THE SELECT LIST. ──────────┐
 * │ It is the MOST RECENT transaction's method for THAT subscription. Doing  │
 * │ it per row in PHP would be one query per subscription — the N+1 this     │
 * │ endpoint is most likely to grow. Doing it as a JOIN would fan the whole  │
 * │ result set out once per transaction, duplicating subscription rows.      │
 * │ `addSelect(subquery)` is neither: MySQL evaluates it per output row and  │
 * │ it cannot change the row count.                                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class ListSubscriptionsForAdmin
{
    /**
     * THE EXACT FILTER SET, VERIFIED AGAINST backend/
     * SubscriptionRepository::allForAdminGroupedSource(). Five, no more.
     *
     * The three phone/code filters are LIKE '%…%' and that is intentional and
     * inherited: `students.student_code` stores 'STU' + 6 digits, so a
     * contains-match lets an admin type either the bare digits or the full
     * code. (Contrast freeze-table row 47, GET /admin/students/by-code/{code},
     * where Stage 4 decision #4 replaced LIKE with normalise-then-exact — that
     * route looks up ONE student by a unique key and owes an exact answer;
     * this one is a search box.)
     *
     * The two id filters are exact equality — an id is not a search term.
     */
    private const TEXT_FILTERS = [
        'phone' => 'st.phone',
        'parent_phone' => 'st.parent_phone',
        'student_code' => 'st.student_code',
    ];

    private const EXACT_FILTERS = [
        'teacher_id' => 'c.teacher_id',
        'course_id' => 's.course_id',
    ];

    /**
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    public function __invoke(array $filters = []): array
    {
        $students = [];

        foreach ($this->rows($filters) as $row) {
            $studentId = (string) $row->student_id;

            $students[$studentId] ??= [
                // BOTH keys hold the STUDENT's id. See the class banner.
                'id' => $studentId,
                'student_id' => $studentId,
                'name' => $row->student_name,
                'username' => $row->student_username,
                // The ACCOUNT's state — users.is_active — not the course's.
                'status' => ((int) $row->account_is_active) === 1 ? 'active' : 'suspended',
                'studentCode' => $row->student_code,
                'phone' => $row->phone,
                'parentPhone' => $row->parent_phone,
                'governorate' => $row->governorate,
                'educationalStage' => $row->educational_stage,
                'gradeLevel' => $row->grade_level,
                'subscriptions' => [],
            ];

            $students[$studentId]['subscriptions'][] = [
                // snake_case AND camelCase for the same value: two frontend
                // components read different spellings. Inherited duplication,
                // preserved deliberately — see the overview action for the
                // twin case on subscriptions_count / enrolled_students.
                'course_id' => (string) $row->course_id,
                'courseId' => (string) $row->course_id,
                'courseName' => $row->course_title,
                'teacherId' => $row->teacher_id !== null ? (string) $row->teacher_id : null,
                'teacherName' => $row->teacher_name,
                // Derived from the row's own status, never from a second query.
                'isActive' => $row->sub_status === 'active',
                'status' => $row->sub_status,
                'expires_at' => $row->expires_at,
                'subscribed_at' => $row->subscribed_at,
                'paymentMethod' => $row->payment_method,
            ];
        }

        return array_values($students);
    }

    /**
     * ONE query, one pass, every field the view needs.
     *
     * ┌── WHY THE QUERY BUILDER AND NOT EAGER-LOADED RELATIONS. ─────────────┐
     * │ `Subscription::with('student.user', 'course.teacher.user')` would be │
     * │ idiomatic and would produce the same data in four round-trips — but  │
     * │ the ORDER BY is `users.full_name`, a column on a table two hops       │
     * │ away, and the five filters range over `students` and `courses`.      │
     * │ Neither sorting nor filtering can be expressed on a relation that    │
     * │ has not been joined, so the joins have to exist regardless; adding   │
     * │ `with()` on top would just fetch the same rows twice.                │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * `students` IS A **LEFT** JOIN, matching backend/. `users` and `courses`
     * are INNER because the FKs guarantee them; a `students` profile row is
     * not guaranteed by anything (subscriptions.student_id references
     * students.user_id, so in practice it is — but backend/ wrote LEFT and a
     * missing profile must degrade to null fields, not to a vanished row).
     *
     * `us.deleted_at IS NULL` IS PRESENT AND backend/ HAD NO SUCH CLAUSE.
     * Stage 0 put SoftDeletes on User, and this is the same accepted
     * correction the admin dashboard counters get: a DELETED student must not
     * appear on the subscriptions screen with a working "suspend account"
     * button. Documented in docs/open-decisions.md (7.3), not slipped in.
     *
     * @param  array<string, string>  $filters
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function rows(array $filters): \Illuminate\Support\Collection
    {
        $query = DB::table('subscriptions as s')
            ->join('users as us', 'us.id', '=', 's.student_id')
            ->leftJoin('students as st', 'st.user_id', '=', 's.student_id')
            ->join('courses as c', 'c.id', '=', 's.course_id')
            ->leftJoin('users as ut', 'ut.id', '=', 'c.teacher_id')
            ->whereNull('us.deleted_at')
            // A soft-deleted COURSE is likewise excluded: everywhere else in
            // this application `Course`'s global scope does it, and only a raw
            // join escapes that scope.
            ->whereNull('c.deleted_at')
            ->select([
                's.student_id',
                's.course_id',
                's.status as sub_status',
                's.expires_at',
                's.created_at as subscribed_at',
                'us.full_name as student_name',
                'us.username as student_username',
                'us.is_active as account_is_active',
                'st.student_code',
                'st.phone',
                'st.parent_phone',
                'st.governorate',
                'st.educational_stage',
                'st.grade_level',
                'c.title as course_title',
                'c.teacher_id',
                'ut.full_name as teacher_name',
            ])
            ->addSelect(['payment_method' => RevenueTransaction::query()
                ->select('payment_method')
                ->whereColumn('revenue_transactions.subscription_id', 's.id')
                ->orderByDesc('created_at')
                ->limit(1),
            ])
            // All rows for one student adjacent, newest enrollment first —
            // byte for byte backend/'s ordering.
            ->orderBy('us.full_name')
            ->orderByDesc('s.created_at');

        $this->applyFilters($query, $filters);

        return $query->get();
    }

    /**
     * Every filter is optional and they are ANDed. An absent or blank value
     * means "no filter", so the unfiltered call ManageSubscriptions.tsx
     * already makes keeps its exact previous behaviour.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, string>  $filters
     */
    private function applyFilters($query, array $filters): void
    {
        foreach (self::TEXT_FILTERS as $key => $column) {
            $value = trim((string) ($filters[$key] ?? ''));

            if ($value !== '') {
                // `%` and `_` in the input are NOT escaped, matching backend/.
                // The value is still BOUND, so this is a wildcard quirk of the
                // search box, never an injection surface.
                $query->where($column, 'like', '%' . $value . '%');
            }
        }

        foreach (self::EXACT_FILTERS as $key => $column) {
            $value = trim((string) ($filters[$key] ?? ''));

            if ($value !== '') {
                $query->where($column, $value);
            }
        }
    }
}
