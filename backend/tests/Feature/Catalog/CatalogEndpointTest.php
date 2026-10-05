<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Grade;
use App\Models\GradeSection;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * /api/v1/catalogs/{type} — the three FLAT option catalogs.
 */
final class CatalogEndpointTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableRateLimiting();
    }

    // ── AUTH SHAPE ────────────────────────────────────────────────────────

    /**
     * TEST 2a: reading a catalog needs NO session at all — a guest gets 200.
     *
     * This changed (decision 10.11): the public, logged-out student-signup
     * wizard reads the `grades` catalog directly for its grade picker since
     * the curricula/countries system was removed, so gating this behind
     * `auth` would 401 the first real step of signup.
     */
    public function test_a_guest_may_read_a_catalog(): void
    {
        $this->makeFlatGrade('الصف الأول الثانوي');

        $this->getJson('/api/v1/catalogs/grades')
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.name', 'الصف الأول الثانوي');
    }

    /** TEST 2b: ANY authenticated role may read — student, instructor, admin. */
    public function test_any_authenticated_role_may_read_a_catalog(): void
    {
        $this->makeFlatGrade('الصف الأول الثانوي');

        foreach (['student', 'instructor', 'admin'] as $role) {
            $user = match ($role) {
                'instructor' => $this->makeInstructor(),
                'admin' => $this->makeAdmin(),
                default => $this->makeUser(),
            };

            $this->loginAs($user);
            $this->startNextRequest();

            $this->getJson('/api/v1/catalogs/grades')
                ->assertStatus(200)
                ->assertJsonPath('data.items.0.name', 'الصف الأول الثانوي');

            $this->postJson('/api/v1/auth/logout');
        }
    }

    /** TEST 3: writing is admin-only — an instructor gets 403, an admin 201. */
    public function test_only_an_admin_may_create_a_catalog_entry(): void
    {
        $section = $this->makeGradeSection('قسم اختباري');

        $this->loginAs($this->makeInstructor());

        $this->postJson('/api/v1/catalogs/grades', ['name' => 'صف جديد', 'section_id' => $section->getKey()])
            ->assertStatus(403);

        $this->postJson('/api/v1/auth/logout');
        $this->loginAs($this->makeAdmin());

        $this->postJson('/api/v1/catalogs/grades', ['name' => 'صف جديد', 'section_id' => $section->getKey()])
            ->assertStatus(201)
            ->assertJsonPath('message', 'تمت الإضافة بنجاح')
            ->assertJsonPath('data.item.name', 'صف جديد');
    }

    // ── THE APPROVED FIX ──────────────────────────────────────────────────

    // ── VALIDATION ────────────────────────────────────────────────────────

    /** TEST 5 (flat half): a duplicate is a 422 with Arabic, not a driver error. */
    public function test_a_duplicate_flat_name_is_a_422_with_the_arabic_message(): void
    {
        $this->makeFlatGrade('الصف الأول الثانوي');
        $this->loginAs($this->makeAdmin());

        $this->postJson('/api/v1/catalogs/grades', ['name' => 'الصف الأول الثانوي'])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.name.0', 'هذا الاسم موجود بالفعل في القائمة');
    }

    public function test_a_missing_or_overlong_name_is_a_422_with_the_arabic_message(): void
    {
        $this->loginAs($this->makeAdmin());

        $this->postJson('/api/v1/catalogs/subjects', ['name' => '   '])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.name.0', 'الاسم مطلوب');

        $this->postJson('/api/v1/catalogs/subjects', ['name' => str_repeat('ا', 101)])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.name.0', 'الاسم يجب ألا يزيد عن 100 حرف');
    }

    /** An unknown {type} is a 404 with the legacy message — before any query. */
    public function test_an_unknown_catalog_type_is_a_404(): void
    {
        $this->loginAs($this->makeAdmin());

        $this->getJson('/api/v1/catalogs/wombats')
            ->assertStatus(404)
            ->assertJsonPath('message', 'نوع القائمة غير معروف');
    }

    // ── DELETE GUARDS ─────────────────────────────────────────────────────

    /** TEST 7: a grade assigned to a teacher is undeletable until unassigned. */
    public function test_a_grade_listed_by_a_teacher_cannot_be_deleted_until_unassigned(): void
    {
        $grade = $this->makeFlatGrade('الصف الثالث الثانوي');
        $instructor = $this->makeInstructor(['الصف الثالث الثانوي'], []);

        $this->loginAs($this->makeAdmin());

        $this->deleteJson("/api/v1/catalogs/grades/{$grade->getKey()}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'لا يمكن الحذف: هذا الخيار مستخدم حالياً لدى 1 مدرس/مدرسين');

        $instructor->teacher->update(['grades' => []]);

        $this->deleteJson("/api/v1/catalogs/grades/{$grade->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم الحذف بنجاح')
            ->assertExactJson([
                'success' => true,
                'message' => 'تم الحذف بنجاح',
                // DELETE returns an empty OBJECT, never null and never [].
                'data' => [],
            ]);

        $this->assertDatabaseMissing('grades', ['id' => $grade->getKey()]);
    }

    /** The subjects catalog uses the other JSON column; prove it is wired up. */
    public function test_a_subject_listed_by_a_teacher_blocks_deletion(): void
    {
        $subject = Subject::query()->create(['name' => 'الفيزياء']);
        $this->makeInstructor([], ['الفيزياء']);

        $this->loginAs($this->makeAdmin());

        $this->deleteJson("/api/v1/catalogs/subjects/{$subject->getKey()}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'لا يمكن الحذف: هذا الخيار مستخدم حالياً لدى 1 مدرس/مدرسين');
    }

    /**
     * TEST 8: a LIVE course blocks the delete; a SOFT-DELETED one does not.
     *
     * The second half is the nuance that must not regress — counting trashed
     * courses would make catalog entries permanently undeletable.
     */
    public function test_a_live_course_blocks_deletion_but_a_soft_deleted_one_does_not(): void
    {
        $live = $this->makeFlatGrade('صف مرتبط بكورس حي');
        $trashedOnly = $this->makeFlatGrade('صف مرتبط بكورس محذوف');
        $instructor = $this->makeInstructor();

        $this->makeCourse($instructor, ['grade_id' => $live->getKey()]);
        $this->makeCourse($instructor, ['grade_id' => $trashedOnly->getKey()], deleted: true);

        $this->loginAs($this->makeAdmin());

        $this->deleteJson("/api/v1/catalogs/grades/{$live->getKey()}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'لا يمكن الحذف: هذا الخيار مرتبط بـ 1 كورس/كورسات');

        $this->deleteJson("/api/v1/catalogs/grades/{$trashedOnly->getKey()}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('grades', ['id' => $trashedOnly->getKey()]);
    }

    // ── RENAME (PATCH) — THE CASCADE ──────────────────────────────────────

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE RENAME TESTS REPLACE THE OLD                                     │
     * │ test_there_is_no_rename_endpoint_for_a_flat_catalog_entry.           │
     * │                                                                      │
     * │ That test pinned an ABSENCE, and the absence existed only because the │
     * │ orphaning problem was unsolved. PATCH now exists WITH the cascade, so │
     * │ the contract it guarded is now guarded by the cascade assertions      │
     * │ below — which are strictly stronger. PUT is still unregistered and    │
     * │ still 404s; that half is kept.                                        │
     * └──────────────────────────────────────────────────────────────────────┘
     */

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE CENTREPIECE. Three teachers carry the old grade name; all three  │
     * │ must carry the new one afterwards, and NO OTHER ELEMENT of their     │
     * │ arrays may move.                                                     │
     * │                                                                      │
     * │ The neighbouring elements are the point: they prove the cascade      │
     * │ replaces the EXACT matching element rather than overwriting the whole │
     * │ array with the renamed value. A blunt full-array write would pass a  │
     * │ "the new name is present" assertion and fail these.                  │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_renaming_a_grade_cascades_into_every_teachers_json_array(): void
    {
        $grade = $this->makeFlatGrade('الصف الثالث الثانوي');

        $a = $this->makeInstructor(['الصف الثالث الثانوي'], ['الفيزياء']);
        $b = $this->makeInstructor(['الصف الأول الثانوي', 'الصف الثالث الثانوي'], []);
        $c = $this->makeInstructor(['الصف الثالث الثانوي', 'الصف الثاني الثانوي'], ['الكيمياء']);

        // A teacher who does NOT carry the old name must be left alone, and a
        // near-miss substring must NOT be matched (whereJsonContains is an
        // exact ELEMENT match, not a LIKE over the serialised array).
        $untouched = $this->makeInstructor(['الصف الثالث الثانوي التجريبي'], []);

        $this->loginAs($this->makeAdmin());

        $this->patchJson("/api/v1/catalogs/grades/{$grade->getKey()}", ['name' => 'الثانوية العامة'])
            ->assertStatus(200)
            ->assertJsonPath('data.item.name', 'الثانوية العامة');

        $this->assertDatabaseHas('grades', ['id' => $grade->getKey(), 'name' => 'الثانوية العامة']);

        // All three renamed, ORDER AND NEIGHBOURS PRESERVED.
        $this->assertSame(['الثانوية العامة'], $a->teacher->fresh()->grades);
        $this->assertSame(['الصف الأول الثانوي', 'الثانوية العامة'], $b->teacher->fresh()->grades);
        $this->assertSame(['الثانوية العامة', 'الصف الثاني الثانوي'], $c->teacher->fresh()->grades);

        // The other JSON column on the same rows is untouched.
        $this->assertSame(['الفيزياء'], $a->teacher->fresh()->subjects);
        $this->assertSame(['الكيمياء'], $c->teacher->fresh()->subjects);

        // Nobody anywhere still carries the old name…
        foreach ([$a, $b, $c] as $user) {
            $this->assertNotContains('الصف الثالث الثانوي', $user->teacher->fresh()->grades);
        }

        // …and the near-miss substring survived verbatim.
        $this->assertSame(['الصف الثالث الثانوي التجريبي'], $untouched->teacher->fresh()->grades);
    }

    /** The subjects catalog uses the other JSON column; prove it cascades too. */
    public function test_renaming_a_subject_cascades_into_every_teachers_json_array(): void
    {
        $subject = $this->makeSubject('الفيزياء');

        $a = $this->makeInstructor(['الصف الأول الثانوي'], ['الفيزياء']);
        $b = $this->makeInstructor([], ['الكيمياء', 'الفيزياء']);
        $c = $this->makeInstructor([], ['الفيزياء', 'الأحياء']);

        $this->loginAs($this->makeAdmin());

        $this->patchJson("/api/v1/catalogs/subjects/{$subject->getKey()}", ['name' => 'الفيزياء الحديثة'])
            ->assertStatus(200);

        $this->assertSame(['الفيزياء الحديثة'], $a->teacher->fresh()->subjects);
        $this->assertSame(['الكيمياء', 'الفيزياء الحديثة'], $b->teacher->fresh()->subjects);
        $this->assertSame(['الفيزياء الحديثة', 'الأحياء'], $c->teacher->fresh()->subjects);

        // The grades column of the same teacher never moved.
        $this->assertSame(['الصف الأول الثانوي'], $a->teacher->fresh()->grades);
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ COURSES NEED NO CASCADE — AND THIS PROVES IT IS THE FK DOING IT,     │
     * │ NOT A COINCIDENCE.                                                    │
     * │                                                                      │
     * │ The course row is snapshotted column-for-column before the rename    │
     * │ and compared after: ZERO writes, `updated_at` included. The new name  │
     * │ nonetheless appears on GET /courses/{id}, because CourseResource      │
     * │ reads it from the joined catalog row rather than from a stored copy.  │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_a_course_picks_up_the_new_name_with_zero_writes_to_the_course_row(): void
    {
        $grade = $this->makeFlatGrade('الصف الثالث الثانوي');
        $subject = $this->makeSubject('الفيزياء');
        $instructor = $this->makeInstructor(['الصف الثالث الثانوي'], ['الفيزياء']);

        $course = $this->makeCourseFor($instructor, [
            'grade_id' => $grade->getKey(),
            'subject_id' => $subject->getKey(),
        ]);

        $before = (array) DB::table('courses')->where('id', $course->getKey())->first();

        $this->loginAs($this->makeAdmin());

        $this->patchJson("/api/v1/catalogs/grades/{$grade->getKey()}", ['name' => 'الثانوية العامة'])
            ->assertStatus(200);
        $this->patchJson("/api/v1/catalogs/subjects/{$subject->getKey()}", ['name' => 'الفيزياء الحديثة'])
            ->assertStatus(200);

        $after = (array) DB::table('courses')->where('id', $course->getKey())->first();

        // Not one column of the course row moved — updated_at included.
        $this->assertSame($before, $after);

        $this->getJson("/api/v1/courses/{$course->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('data.grade_name', 'الثانوية العامة')
            ->assertJsonPath('data.subject_name', 'الفيزياء الحديثة');
    }

    /** Nothing referencing the entry at all — the cascade is a trivial no-op. */
    public function test_renaming_an_entry_no_teacher_references_succeeds_trivially(): void
    {
        $grade = $this->makeFlatGrade('صف لا يستخدمه أحد');
        $this->makeInstructor(['صف آخر تماماً'], []);

        $this->loginAs($this->makeAdmin());

        $this->patchJson("/api/v1/catalogs/grades/{$grade->getKey()}", ['name' => 'صف أعيدت تسميته'])
            ->assertStatus(200)
            ->assertJsonPath('data.item.name', 'صف أعيدت تسميته');

        $this->assertDatabaseHas('grades', ['id' => $grade->getKey(), 'name' => 'صف أعيدت تسميته']);
    }

    // ── RENAME — VALIDATION AND AUTH ──────────────────────────────────────

    /** A collision inside the SAME catalog is a 422, and nothing is written. */
    public function test_renaming_onto_an_existing_name_is_a_422_and_changes_nothing(): void
    {
        $taken = $this->makeFlatGrade('الصف الأول الثانوي');
        $grade = $this->makeFlatGrade('الصف الثاني الثانوي');
        $instructor = $this->makeInstructor(['الصف الثاني الثانوي'], []);

        $this->loginAs($this->makeAdmin());

        $this->patchJson("/api/v1/catalogs/grades/{$grade->getKey()}", ['name' => 'الصف الأول الثانوي'])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.name.0', 'هذا الاسم موجود بالفعل في القائمة');

        $this->assertDatabaseHas('grades', ['id' => $grade->getKey(), 'name' => 'الصف الثاني الثانوي']);
        $this->assertDatabaseHas('grades', ['id' => $taken->getKey(), 'name' => 'الصف الأول الثانوي']);
        $this->assertSame(['الصف الثاني الثانوي'], $instructor->teacher->fresh()->grades);
    }

    /**
     * A no-op rename (the row's OWN name) is NOT a collision. The duplicate
     * check excludes the row being renamed, so this is a 200 — the alternative
     * would make an unchanged form submit fail for no reason.
     */
    public function test_renaming_an_entry_to_its_own_name_is_allowed(): void
    {
        $grade = $this->makeFlatGrade('الصف الأول الثانوي');

        $this->loginAs($this->makeAdmin());

        $this->patchJson("/api/v1/catalogs/grades/{$grade->getKey()}", ['name' => 'الصف الأول الثانوي'])
            ->assertStatus(200)
            ->assertJsonPath('data.item.name', 'الصف الأول الثانوي');
    }

    /** Whitespace-only is a MISSING name, exactly as on the create path. */
    public function test_renaming_to_an_empty_or_overlong_name_is_a_422(): void
    {
        $subject = $this->makeSubject('الفيزياء');

        $this->loginAs($this->makeAdmin());

        $this->patchJson("/api/v1/catalogs/subjects/{$subject->getKey()}", ['name' => '   '])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.name.0', 'الاسم مطلوب');

        $this->patchJson("/api/v1/catalogs/subjects/{$subject->getKey()}", ['name' => str_repeat('ا', 101)])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.name.0', 'الاسم يجب ألا يزيد عن 100 حرف');

        $this->assertDatabaseHas('subjects', ['id' => $subject->getKey(), 'name' => 'الفيزياء']);
    }

    /** Renaming is admin-only — it sits in the same role:admin group. */
    public function test_only_an_admin_may_rename_a_catalog_entry(): void
    {
        $grade = $this->makeFlatGrade('الصف الأول الثانوي');

        foreach (['instructor', 'student'] as $role) {
            $user = $role === 'instructor' ? $this->makeInstructor() : $this->makeUser();

            $this->loginAs($user);
            $this->startNextRequest();

            $this->patchJson("/api/v1/catalogs/grades/{$grade->getKey()}", ['name' => 'اسم آخر'])
                ->assertStatus(403);

            $this->postJson('/api/v1/auth/logout');
        }

        $this->assertDatabaseHas('grades', ['name' => 'الصف الأول الثانوي']);
    }

    /** PUT is still unregistered — PATCH is the only rename verb. */
    public function test_put_is_not_a_rename_verb(): void
    {
        $grade = $this->makeFlatGrade('الصف الأول الثانوي');

        $this->loginAs($this->makeAdmin());

        $this->putJson("/api/v1/catalogs/grades/{$grade->getKey()}", ['name' => 'اسم آخر'])
            ->assertStatus(404);

        $this->assertDatabaseHas('grades', ['name' => 'الصف الأول الثانوي']);
    }

    // ── STUDENT GUARD / CASCADE (decision 10.11) ────────────────────────────

    /**
     * A grade currently held by a student blocks deletion too — the THIRD
     * guard, reached only once the teacher and course guards both pass.
     * `subjects` has no student column at all (CatalogType::
     * studentGradeColumn() is null for it), so this guard never applies there.
     */
    public function test_a_grade_held_by_a_student_cannot_be_deleted_until_reassigned(): void
    {
        $grade = $this->makeFlatGrade('الصف الأول الإعدادي');
        $student = $this->makeStudent('القاهرة', 'الصف الأول الإعدادي');

        $this->loginAs($this->makeAdmin());

        $this->deleteJson("/api/v1/catalogs/grades/{$grade->getKey()}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'لا يمكن الحذف: هذا الخيار مستخدم حالياً لدى 1 طالب/طلاب');

        $student->update(['grade_level' => 'صف آخر']);

        $this->deleteJson("/api/v1/catalogs/grades/{$grade->getKey()}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('grades', ['id' => $grade->getKey()]);
    }

    /** A subject is never held by a student, so it is never blocked this way. */
    public function test_deleting_a_subject_never_checks_students(): void
    {
        $subject = $this->makeSubject('الفيزياء');
        // A student whose grade_level HAPPENS to equal the subject's name —
        // must not be mistaken for a reference; subjects has no student column.
        $this->makeStudent('القاهرة', 'الفيزياء');

        $this->loginAs($this->makeAdmin());

        $this->deleteJson("/api/v1/catalogs/subjects/{$subject->getKey()}")
            ->assertStatus(200);
    }

    /**
     * Renaming a grade cascades into students.grade_level too — a plain
     * string UPDATE, not a JSON read-modify-write like teachers.grades.
     */
    public function test_renaming_a_grade_cascades_into_students_grade_level(): void
    {
        $grade = $this->makeFlatGrade('الصف الأول الإعدادي');
        $a = $this->makeStudent('القاهرة', 'الصف الأول الإعدادي');
        $b = $this->makeStudent('الجيزة', 'الصف الأول الإعدادي');
        $untouched = $this->makeStudent('أسوان', 'صف آخر تماماً');

        $this->loginAs($this->makeAdmin());

        $this->patchJson("/api/v1/catalogs/grades/{$grade->getKey()}", ['name' => 'إعدادي أول'])
            ->assertStatus(200);

        $this->assertSame('إعدادي أول', $a->fresh()->grade_level);
        $this->assertSame('إعدادي أول', $b->fresh()->grade_level);
        $this->assertSame('صف آخر تماماً', $untouched->fresh()->grade_level);
    }

    // ── REORDER (decision 10.11) ────────────────────────────────────────────

    /** A full, valid permutation of ids is applied in the given order. */
    public function test_admin_can_reorder_grades(): void
    {
        $first = $this->makeFlatGrade('أولاً');
        $second = $this->makeFlatGrade('ثانياً');
        $third = $this->makeFlatGrade('ثالثاً');

        $this->loginAs($this->makeAdmin());

        $this->patchJson('/api/v1/catalogs/grades/reorder', [
            'section_id' => $first->section_id,
            'order' => [$third->getKey(), $first->getKey(), $second->getKey()],
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.name', 'ثالثاً')
            ->assertJsonPath('data.items.1.name', 'أولاً')
            ->assertJsonPath('data.items.2.name', 'ثانياً');

        // Persisted, not just echoed back — a fresh read shows the same order.
        $this->getJson('/api/v1/catalogs/grades')
            ->assertJsonPath('data.items.0.name', 'ثالثاً')
            ->assertJsonPath('data.items.1.name', 'أولاً')
            ->assertJsonPath('data.items.2.name', 'ثانياً');
    }

    /** A list missing a row, containing an unknown id, or with a duplicate is rejected. */
    public function test_reorder_rejects_anything_other_than_an_exact_permutation(): void
    {
        // makeFlatGrade() doesn't set `order` (only CatalogController::store()
        // does, via creationAttributes()) — both rows share the column
        // default here, which is fine: the point of this test is that they
        // are UNCHANGED after every rejected attempt, not what they started as.
        $first = $this->makeFlatGrade('أولاً');
        $second = $this->makeFlatGrade('ثانياً');
        $orderBefore = [$first->fresh()->order, $second->fresh()->order];

        $this->loginAs($this->makeAdmin());
        $sectionId = $first->section_id;

        // Missing a row.
        $this->patchJson('/api/v1/catalogs/grades/reorder', [
            'section_id' => $sectionId,
            'order' => [$first->getKey()],
        ])->assertStatus(422);

        // Unknown id.
        $this->patchJson('/api/v1/catalogs/grades/reorder', [
            'section_id' => $sectionId,
            'order' => [$first->getKey(), $second->getKey(), '01a00000-0000-7000-8000-000000000000'],
        ])->assertStatus(422);

        // Duplicate.
        $this->patchJson('/api/v1/catalogs/grades/reorder', [
            'section_id' => $sectionId,
            'order' => [$first->getKey(), $first->getKey()],
        ])->assertStatus(422);

        // Nothing above ever wrote anything.
        $this->assertSame($orderBefore, [$first->fresh()->order, $second->fresh()->order]);
    }

    /** `subjects` has no `order` column and stays alphabetical — reorder is refused. */
    public function test_reorder_is_refused_for_a_catalog_type_that_does_not_support_it(): void
    {
        $a = $this->makeSubject('الأحياء');
        $b = $this->makeSubject('الفيزياء');

        $this->loginAs($this->makeAdmin());

        $this->patchJson('/api/v1/catalogs/subjects/reorder', [
            'order' => [$b->getKey(), $a->getKey()],
        ])->assertStatus(422);
    }

    /** Reordering is admin-only. */
    public function test_only_an_admin_may_reorder_grades(): void
    {
        $first = $this->makeFlatGrade('أولاً');
        $second = $this->makeFlatGrade('ثانياً');

        $this->loginAs($this->makeInstructor());

        $this->patchJson('/api/v1/catalogs/grades/reorder', [
            'order' => [$second->getKey(), $first->getKey()],
        ])->assertStatus(403);
    }

    // ── SECTIONS (decision 10.12) ───────────────────────────────────────────

    /** grade_sections reads through the exact same public route as grades/subjects. */
    public function test_a_guest_may_read_sections(): void
    {
        $this->makeGradeSection('قسم اختباري للقراءة العامة');

        $this->getJson('/api/v1/catalogs/grade_sections')
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'قسم اختباري للقراءة العامة']);
    }

    /** Sections are a flat, unscoped catalog — no section_id needed to create one. */
    public function test_admin_can_create_a_section(): void
    {
        $this->loginAs($this->makeAdmin());

        $this->postJson('/api/v1/catalogs/grade_sections', ['name' => 'قسم جديد'])
            ->assertStatus(201)
            ->assertJsonPath('data.item.name', 'قسم جديد');
    }

    /** A grade cannot be created without naming a real section. */
    public function test_creating_a_grade_requires_a_valid_section_id(): void
    {
        $this->loginAs($this->makeAdmin());

        $this->postJson('/api/v1/catalogs/grades', ['name' => 'صف بلا قسم'])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.section_id.0', 'القسم الدراسي مطلوب');

        $this->postJson('/api/v1/catalogs/grades', [
            'name' => 'صف بقسم وهمي',
            'section_id' => '01a00000-0000-7000-8000-000000000000',
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.section_id.0', 'القسم الدراسي غير موجود');
    }

    /** A section holding grades cannot be deleted until every grade is gone. */
    public function test_deleting_a_section_with_grades_is_blocked_until_empty(): void
    {
        $section = $this->makeGradeSection('قسم به صفوف');
        $grade = $this->makeFlatGrade('صف تابع', $section->getKey());

        $this->loginAs($this->makeAdmin());

        $this->deleteJson("/api/v1/catalogs/grade_sections/{$section->getKey()}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'لا يمكن الحذف: هذا القسم يحتوي على 1 صف/صفوف دراسية');

        $this->deleteJson("/api/v1/catalogs/grades/{$grade->getKey()}")
            ->assertStatus(200);

        $this->deleteJson("/api/v1/catalogs/grade_sections/{$section->getKey()}")
            ->assertStatus(200);
    }

    /**
     * Sections reorder as one flat list, exactly like the old global grades
     * reorder did. The exact-permutation check covers EVERY section row —
     * including the two the "section_id"-backfill migration seeds by default
     * — so the submitted order lists $b and $a first, followed by whatever
     * else already exists, rather than assuming an empty table.
     */
    public function test_admin_can_reorder_sections(): void
    {
        $a = $this->makeGradeSection('قسم اختباري أ');
        $b = $this->makeGradeSection('قسم اختباري ب');

        $this->loginAs($this->makeAdmin());

        $others = GradeSection::query()
            ->whereKeyNot([$a->getKey(), $b->getKey()])
            ->pluck('id')
            ->all();

        $this->patchJson('/api/v1/catalogs/grade_sections/reorder', [
            'order' => array_merge([$b->getKey(), $a->getKey()], $others),
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.name', 'قسم اختباري ب')
            ->assertJsonPath('data.items.1.name', 'قسم اختباري أ');
    }

    /**
     * Reordering grades is scoped to ONE section: a permutation that mixes in
     * an id from a different section is rejected, not silently accepted.
     */
    public function test_grade_reorder_is_scoped_to_its_section(): void
    {
        $sectionA = $this->makeGradeSection('قسم أ');
        $sectionB = $this->makeGradeSection('قسم ب');

        $a1 = $this->makeFlatGrade('أ1', $sectionA->getKey());
        $a2 = $this->makeFlatGrade('أ2', $sectionA->getKey());
        $b1 = $this->makeFlatGrade('ب1', $sectionB->getKey());

        $this->loginAs($this->makeAdmin());

        // Valid: a permutation of section A's own two grades.
        $this->patchJson('/api/v1/catalogs/grades/reorder', [
            'section_id' => $sectionA->getKey(),
            'order' => [$a2->getKey(), $a1->getKey()],
        ])->assertStatus(200);

        // Invalid: section A's permutation must not include section B's grade.
        $this->patchJson('/api/v1/catalogs/grades/reorder', [
            'section_id' => $sectionA->getKey(),
            'order' => [$a1->getKey(), $a2->getKey(), $b1->getKey()],
        ])->assertStatus(422);
    }
}
