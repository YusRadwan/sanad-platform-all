<?php

declare(strict_types=1);

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\BroadcastNotificationController;
use App\Http\Controllers\CenterCodeController;
use App\Http\Controllers\CenterCourseController;
use App\Http\Controllers\CenterGroupController;
use App\Http\Controllers\CenterPaymentController;
use App\Http\Controllers\CenterReportController;
use App\Http\Controllers\CenterSessionController;
use App\Http\Controllers\CenterStudentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\ExamStatisticsController;
use App\Http\Controllers\QuestionBankController;
use App\Http\Controllers\ContentItemController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\EnrollmentCodeController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LessonController;
use App\Http\Controllers\RevenueController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TeacherApplicationController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VideoStatisticsController;
use App\Support\EmployeePermissions;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| Mounted under the `/api` prefix with the `api` middleware group, which
| bootstrap/app.php defines as:
|
|   HandleCors            reflect the caller's Origin, answer preflight
|   SecurityHeaders       baseline headers on every response, errors included
|   ForceJsonEnvelope     {success, message, data} on everything
|   EncryptCookies        (except sanad_csrf)
|   AddQueuedCookiesToResponse
|   StartSession          sanad_session cookie, 120 min, Lax, HttpOnly
|   ShareErrorsFromSession
|   VerifyCsrfToken       sanad_csrf cookie + X-CSRF-Token header
|   SubstituteBindings
|
| ORDERING NOTE: SecurityHeaders and ForceJsonEnvelope sit near the OUTSIDE of
| the stack on purpose. Middleware wraps the request going in and the response
| coming out, so an outer position means they also process responses produced
| by anything further in — including a CSRF rejection, a throttle, and the
| exception handler's own output. That is what makes "headers and envelope on
| EVERY response" true rather than aspirational.
|
| The `v1` prefix is explicit rather than derived from an API-Version header:
| the frontend hard-codes `/api/v1` in its axios baseURL, and a URL-visible
| version is trivially cacheable and greppable.
|
*/

Route::prefix('v1')->group(function (): void {

    /*
     * The ONLY endpoint in Stage 0.
     *
     * It exists so the whole pipeline — envelope, exception mapping, CORS,
     * security headers, session start, CSRF cookie issuance — can be tested
     * end to end against something real before any feature exists.
     *
     * GET-only ON PURPOSE. tests/Feature/HealthEndpointTest.php asserts that
     * POST /api/v1/health answers 404 (NOT 405), which is the behaviour
     * backend/'s router had and which App\Exceptions\Handler preserves.
     *
     * No auth middleware: an external monitor cannot hold a session.
     */
    Route::get('/health', [HealthController::class, 'index'])->name('health');

    /*
    |--------------------------------------------------------------------------
    | Auth (Stage 1)
    |--------------------------------------------------------------------------
    |
    | Six endpoints, matching frontend/src/services/authService.ts exactly.
    |
    | ┌── MIDDLEWARE, AND WHY EACH ONE SITS WHERE IT DOES ──────────────────┐
    | │                                                                      │
    | │ login / register are CSRF-EXEMPT. Not a weakening: they are the      │
    | │ unauthenticated bootstrap endpoints and a client cannot possibly     │
    | │ hold a token before it has a session. The exemption is declared in   │
    | │ config('session.csrf_except') — Stage 0 pre-registered exactly these │
    | │ two paths, so nothing about the CSRF plumbing had to be reopened.    │
    | │                                                                      │
    | │ throttle.lockout runs FIRST on every route that has it, so abusive   │
    | │ traffic is rejected before any database work. On                     │
    | │ update-credentials that specifically means BEFORE `auth`, which      │
    | │ would otherwise issue a user lookup per hostile request. Laravel     │
    | │ applies route middleware in the order listed, so the listed order IS │
    | │ the execution order.                                                 │
    | │                                                                      │
    | │ `active` (EnsureUserIsActive) always follows `auth`: it needs the    │
    | │ resolved user, and it is what makes a mid-session deactivation or    │
    | │ soft-delete take effect on the very next request instead of at       │
    | │ cookie expiry.                                                       │
    | └──────────────────────────────────────────────────────────────────────┘
    |
    | NOTE the deliberate asymmetry on /auth/refresh — it carries NO auth
    | middleware and throws its own 401 from inside the handler. See the
    | controller method's docblock: the frontend's retry interceptor calls it
    | ON a 401, and that recovery path must not itself be gated by auth.
    */
    Route::prefix('auth')->name('auth.')->group(function (): void {

        // 5/min per caller AND 10/min per targeted username, escalating lockout.
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle.lockout:login')
            ->name('login');

        // 3 signups per hour per IP — mass account-creation abuse.
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle.lockout:register')
            ->name('register');

        // PUBLIC ON PURPOSE. Do not add `auth` here.
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('refresh');

        Route::middleware(['auth', 'active'])->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('/me', [AuthController::class, 'me'])->name('me');

            /*
             * "رجوع للأدمن" — open decision 10.18. Deliberately NOT
             * `role:admin`: while impersonating, auth()->user() resolves to
             * the STUDENT, so a role gate here would 403 the very call meant
             * to end that state. The session key
             * (App\Support\Impersonation::SESSION_KEY) IS the authorization —
             * StopImpersonation 404/401s on its own if none is present or the
             * stashed admin no longer checks out.
             */
            Route::post('/stop-impersonation', [AuthController::class, 'stopImpersonation'])
                ->name('stop-impersonation');
        });

        // The current password is verified here, so this is a brute-force
        // target too: its own bucket, ahead of the auth lookup.
        Route::patch('/update-credentials', [AuthController::class, 'updateCredentials'])
            ->middleware(['throttle.lockout:update-credentials', 'auth', 'active'])
            ->name('update-credentials');
    });

    /*
    |--------------------------------------------------------------------------
    | Option catalogs — grades / subjects (Stage 2)
    |--------------------------------------------------------------------------
    |
    | The two FLAT lists an admin manages in AddTeacher.tsx's "التصنيفات"
    | step. {type} is resolved through the App\Enums\CatalogType backed enum as
    | the first statement of every action, so an unknown segment is a 404
    | ('نوع القائمة غير معروف') before any query is built — and no table name
    | is ever derived from caller input.
    |
    | ┌─ READ IS PUBLIC. DO NOT ADD `auth` BACK. ─────────────────────────────┐
    | │                                                                      │
    | │ The rule this app follows is "public if a logged-out surface renders │
    | │ it". This USED to be session-gated (nothing logged-out rendered      │
    | │ these lists), but the platform's curricula/countries system was      │
    | │ removed and the public, logged-out student-signup wizard now reads   │
    | │ the `grades` catalog directly for its grade picker — gating this     │
    | │ would 401 the first step of signup. Display-only data (catalog       │
    | │ names), nothing sensitive.                                           │
    | └──────────────────────────────────────────────────────────────────────┘
    |
    | PATCH .../reorder (admin) EXISTS ONLY FOR `grades` — subjects have no
    | `order` column and stay alphabetical; ReorderCatalogRequest rejects a
    | reorder attempt on any type where CatalogType::supportsOrdering() is
    | false. Registered BEFORE PATCH .../{id} so the literal `reorder` segment
    | is never swallowed by the dynamic {id} route (Laravel matches in
    | registration order).
    |
    | PATCH .../{id} (rename) EXISTS AND IS SAFE. It once did not: teachers.
    | grades/.subjects are JSON arrays of NAME STRINGS and students.grade_level
    | is a plain string column — none are foreign keys, so a bare rename would
    | orphan every teacher/student carrying the old name. CatalogController::
    | update() renames the row AND cascades into every one of those, inside one
    | transaction. Courses need no cascade: they reference catalog rows by FK
    | (courses.grade_id / subject_id) and pick the name up from a live lookup.
    | See the controller docblock.
    */
    Route::get('/catalogs/{type}', [CatalogController::class, 'index'])
        ->name('catalogs.index');

    Route::middleware(['auth', 'active', 'role:admin'])->group(function (): void {
        Route::post('/catalogs/{type}', [CatalogController::class, 'store'])
            ->name('catalogs.store');
        Route::patch('/catalogs/{type}/reorder', [CatalogController::class, 'reorder'])
            ->name('catalogs.reorder');
        Route::patch('/catalogs/{type}/{id}', [CatalogController::class, 'update'])
            ->name('catalogs.update');
        Route::delete('/catalogs/{type}/{id}', [CatalogController::class, 'destroy'])
            ->name('catalogs.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Teachers, students, user management (Stage 4) — 19 routes
    |--------------------------------------------------------------------------
    |
    | Freeze-table rows 30, 32-34 and 35-51. One row of that table is
    | deliberately NOT implemented here — see the note at the end of this
    | block.
    |
    | ┌── WHY THERE IS NO Policy ON ANY OF THESE 19 ────────────────────────┐
    | │                                                                      │
    | │ THE 14 ADMIN ROUTES: `role:admin` is the whole rule. A Policy could  │
    | │ only say `return $user->isAdmin()`, and Stage 3's Gate::before       │
    | │ returns true for an admin BEFORE any Policy runs — so the Policy     │
    | │ would never execute at all. It would be ceremony with zero security  │
    | │ value, and it would move the access rule out of THIS file, which is  │
    | │ where anyone asking "who can call this?" looks first. The freeze     │
    | │ table reaches the same conclusion for all 25 rows of the /admin      │
    | │ group.                                                               │
    | │                                                                      │
    | │ THE 3 SELF-SERVICE ROUTES: a Policy answers "may this user act on    │
    | │ THIS row" — and none of these three lets the caller name a row. The  │
    | │ subject is the session user, full stop. There is nothing to          │
    | │ authorise beyond the role gate.                                      │
    | │                                                                      │
    | │ THE PUBLIC ROUTE: no auth at all; the QUERY is the boundary. See     │
    | │ Teacher::scopePubliclyVisible().                                     │
    | └──────────────────────────────────────────────────────────────────────┘
    */

    /*
     * PUBLIC INSTRUCTOR DIRECTORY. Do NOT add `auth`.
     *
     * CourseDetailsPage.tsx resolves a course's teacher to a profile link for
     * LOGGED-OUT visitors, so gating this would break the public storefront —
     * the same rule the /catalogs reads follow (session-gated, since nothing
     * anonymous renders them).
     *
     * Contact details are excluded AT THE QUERY LEVEL, not by the presenter:
     * scopePubliclyVisible() never selects email or phone, so no future edit
     * to PublicTeacher can leak them.
     *
     * REGISTERED BEFORE the /teachers/statistics route below. Laravel matches
     * in registration order, and while these two paths cannot actually collide
     * (different segment counts), the ordering mirrors backend/'s and keeps the
     * public route visibly first.
     */
    Route::get('/teachers', [TeacherController::class, 'publicIndex'])
        ->name('teachers.public');

    /*
     * INSTRUCTOR SELF-SERVICE. Identity from the session, never the request.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ NEITHER ROUTE ACCEPTS AN ID, AND NEITHER MAY EVER BE GIVEN ONE.      │
     * │                                                                      │
     * │ The handlers read $request->user() and nothing else — they never     │
     * │ touch query input at all, so a `?teacher_id=` appended by a caller   │
     * │ has no code path that could observe it. That is a stronger guarantee │
     * │ than validating-and-rejecting the parameter, and it is pinned by     │
     * │ tests that send one and assert the response is byte-identical.       │
     * │                                                                      │
     * │ Do not "add admin flexibility" here. Platform-wide numbers already   │
     * │ live behind GET /admin/dashboard (Stage 9).                          │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    Route::middleware(['auth', 'active', 'role:instructor'])->group(function (): void {
        Route::get('/teachers/statistics', [TeacherController::class, 'statistics'])
            ->middleware('employee.permission:' . EmployeePermissions::STATISTICS_VIEW)
            ->name('teachers.statistics');

        Route::get('/enrollments/instructor-students', [EnrollmentController::class, 'instructorStudents'])
            ->middleware('employee.permission:' . EmployeePermissions::SUBSCRIPTIONS_VIEW)
            ->name('enrollments.instructor-students');
    });

    /*
     * STUDENT SELF-SERVICE. Same rule, same reason: no id in the URL, no id
     * read from the request.
     */
    Route::middleware(['auth', 'active', 'role:student'])->group(function (): void {
        Route::get('/students/dashboard', [StudentController::class, 'dashboard'])
            ->name('students.dashboard');
        Route::get('/students/watched-videos', [StudentController::class, 'watchedVideos'])
            ->name('students.watched-videos');
        Route::get('/students/exam-attempts', [StudentController::class, 'examAttempts'])
            ->name('students.exam-attempts');
    });

    /*
    |--------------------------------------------------------------------------
    | طلب مدرس جديد — Teacher Applications. Freeze-table row 119. Open
    | decision 10.23.
    |--------------------------------------------------------------------------
    |
    | PUBLIC, NO SESSION. JoinUsSection.tsx's "ابدأ التدريس معنا الآن" button
    | sends a visitor here with no account at all — gating this with `auth`
    | would make the button unusable for its entire intended audience.
    |
    | `throttle.lockout:teacher-application` is the whole defence against a
    | spam flood, same shape as `register`'s bucket (mass-submission abuse
    | from one IP, config/ratelimit.php).
    |
    | The ADMIN read (row 120, GET /admin/teacher-applications) lives inside
    | the ordinary `role:admin` group directly below rather than its own
    | group, exactly like every other admin-only GET in this file.
    */
    Route::post('/teacher-applications', [TeacherApplicationController::class, 'store'])
        ->middleware('throttle.lockout:teacher-application')
        ->name('teacher-applications.store');

    /*
     * ADMIN CRUD — users, teachers, students.
     */
    Route::middleware(['auth', 'active', 'role:admin'])
        ->prefix('admin')
        ->name('admin.')
        ->group(function (): void {

            // ManageUsers.tsx ("جدول المستخدمين") — decision 10.40.
            Route::get('/users', [UserController::class, 'index'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_USERS_VIEW)
                ->name('users.index');
            Route::get('/users/{id}', [UserController::class, 'show'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_USERS_VIEW)
                ->name('users.show');
            Route::patch('/users/{id}', [UserController::class, 'update'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_USERS_VIEW)
                ->name('users.update');
            Route::delete('/users/{id}', [UserController::class, 'destroy'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_USERS_VIEW)
                ->name('users.destroy');

            // GET /teachers is a SHARED picker (ManageTeachers.tsx, EnrollmentCodes.tsx,
            // FilterEnrollmentCodes.tsx, GenerateCenterCodes.tsx, BroadcastNotifications.tsx,
            // ManageSubscriptions.tsx all call adminService.getAllTeachers()) — no single
            // ADMIN_NAV page owns it, so it is deliberately left ungated beyond role:admin,
            // same reasoning as /catalogs. AddTeacher.tsx ("إضافة مدرس") is POST's only caller.
            Route::get('/teachers', [TeacherController::class, 'index'])->name('teachers.index');
            Route::post('/teachers', [TeacherController::class, 'store'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_ADD_TEACHER_MANAGE)
                ->name('teachers.store');

            /*
             * ✅ LANDED IN STAGE 5 / SESSION A — freeze-table row 41, deferred
             * from Stage 4 with this comment in its place.
             *
             * The handler is CourseController::byTeacher because this is
             * course logic, not teacher logic, and because it must return the
             * teacher's DRAFTS — ownership, never a visibility scope. Decision
             * 3.6 hides unpublished courses from OTHERS; an admin issuing an
             * enrolment code for a course that is not published yet is exactly
             * the case it must not hide.
             *
             * REGISTERED **ABOVE** '/teachers/{id}', as Stage 4 required. The
             * two paths differ in segment count so they cannot collide today,
             * but backend/ marked this route ORDER-SENSITIVE and the
             * convention is kept so a future '/teachers/{id}' wildcard cannot
             * swallow it.
             */
            // SHARED between EnrollmentCodes.tsx's course picker (the case this route was
            // built for, per the comment above) and ManageSubscriptions.tsx's own course
            // picker — no single ADMIN_NAV page owns it, left ungated beyond role:admin.
            Route::get('/teachers/{id}/courses', [CourseController::class, 'byTeacher'])
                ->name('teachers.courses');

            // ManageTeachers.tsx ("إدارة المدرسين") — decision 10.40.
            Route::get('/teachers/{id}', [TeacherController::class, 'show'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_TEACHERS_MANAGE)
                ->name('teachers.show');

            /*
             * BOTH VERBS, ONE HANDLER — preserved from backend/ exactly.
             * ManageTeachers.tsx sends PUT for its تفعيل/إيقاف toggle and
             * PATCH for the general edit form. Both carry partial payloads and
             * mean the same thing, so mapping them to one action is honest;
             * dropping either would break a live frontend flow.
             */
            Route::match(['patch', 'put'], '/teachers/{id}', [TeacherController::class, 'update'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_TEACHERS_MANAGE)
                ->name('teachers.update');

            Route::delete('/teachers/{id}', [TeacherController::class, 'destroy'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_TEACHERS_MANAGE)
                ->name('teachers.destroy');

            // SHARED between ManageUsers.tsx ("جدول المستخدمين" — adminService.getStudents)
            // and ResetStudentPassword.tsx's phone lookup — no single ADMIN_NAV page owns
            // it, left ungated beyond role:admin, same reasoning as GET /teachers above.
            Route::get('/students', [StudentController::class, 'index'])->name('students.index');

            /*
             * ORDER-SENSITIVE IN SPIRIT: '/students/by-code/{code}' has one
             * more segment than '/students/{id}' so they cannot collide, but
             * it is kept above for the same convention as the teacher courses
             * route.
             */
            // StudentCodeDetails.tsx ("تفاصيل كود الطالب") — decision 10.40.
            Route::get('/students/by-code/{code}', [StudentController::class, 'showByCode'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_STUDENT_CODE_DETAILS_VIEW)
                ->name('students.by-code');

            // EditStudent.tsx ("تعديل بيانات الطالب"), reached only from ManageUsers.tsx's
            // row action — decision 10.40.
            Route::get('/students/{id}', [StudentController::class, 'show'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_USERS_VIEW)
                ->name('students.show');

            // SHARED by ManageUsers.tsx's status toggle, EditStudent.tsx's full profile
            // save AND ResetStudentPassword.tsx's password write (adminService.updateStudent
            // backs all three) — no single ADMIN_NAV page owns it, left ungated beyond
            // role:admin. ADMIN_RESET_STUDENT_PASSWORD_MANAGE in particular has no route of
            // its own as a result; see open-decisions.md 10.40 for the flagged follow-up.
            Route::match(['patch', 'put'], '/students/{id}', [StudentController::class, 'update'])
                ->name('students.update');

            // ManageUsers.tsx ("جدول المستخدمين") — decision 10.40.
            Route::delete('/students/{id}', [StudentController::class, 'destroy'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_USERS_VIEW)
                ->name('students.destroy');

            /*
             * "تحويل إلى حساب الطالب" — open decision 10.18. One more segment
             * than '/students/{id}' so it cannot collide with the three
             * routes above it, same convention as '/students/by-code/{code}'.
             * See App\Actions\Auth\StartImpersonation for the full design
             * (session-swap only, no login_sessions row, admin-only).
             */
            // ManageUsers.tsx ("جدول المستخدمين") — decision 10.40.
            Route::post('/students/{id}/impersonate', [StudentController::class, 'impersonate'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_USERS_VIEW)
                ->name('students.impersonate');

            /*
             * ─── STAGE 7 / SESSION A — the six ADMIN routes. ───────────────
             * Freeze-table rows 53, 54, 55, 57, 58, 59. The seventh route of
             * this session (row 60, redemption) is `role:student` and is
             * registered in its own group further down.
             *
             * `role:admin` IS THE WHOLE RULE for all six, exactly as the
             * freeze table's admin block states. No Policy is consulted and
             * none should be added: every one of them would reduce to
             * `return $user->isAdmin()`, which Gate::before answered before
             * any Policy method could be entered.
             *
             * ORDER-SENSITIVE, AND HERE IT IS REAL — not "in spirit" like the
             * two Stage 4 pairs above. '/enrollment-codes' (GET, index) and
             * '/enrollment-codes/{code}' (GET, show) differ by one segment so
             * they cannot collide, BUT the wildcard is `{code}` and Laravel
             * matches in registration order: registering show() first would
             * still be safe today, yet any future '/enrollment-codes/recent'
             * literal added below the wildcard would be swallowed by it. The
             * literal-before-wildcard convention this file has kept since
             * Stage 4 is honoured so that hazard never arises.
             *
             * SESSION B ADDS ROW 52 (GET /admin/dashboard) BELOW. ROW 56
             * (GET /admin/login-activity) REMAINS ABSENT — it is STAGE 10's,
             * not Session B's: it reads `login_sessions` and belongs with the
             * session-lifecycle work. Deliberately not stubbed.
             */
            // No employee.permission gate, matching DASHBOARD_VIEW's own precedent
            // (EmployeePermissions.php) — AdminHome.tsx always renders regardless of
            // permissions, so this route must stay reachable by every admin-owned
            // Employee/Amin, not just one holding a specific admin_statistics key.
            Route::get('/dashboard', [AdminDashboardController::class, 'stats'])
                ->name('dashboard');

            // ManageSubscriptions.tsx ("جداول الاشتراكات") — decision 10.40.
            Route::get('/subscriptions', [AdminDashboardController::class, 'subscriptions'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_SUBSCRIPTIONS_MANAGE)
                ->name('subscriptions.index');

            Route::put('/enrollments/toggle', [AdminDashboardController::class, 'toggleEnrollment'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_SUBSCRIPTIONS_MANAGE)
                ->name('enrollments.toggle');

            // Statistics.tsx ("إحصائيات المنصة") — decision 10.40.
            Route::get('/courses/overview', [AdminDashboardController::class, 'coursesOverview'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_STATISTICS_VIEW)
                ->name('courses.overview');

            // EnrollmentCodes.tsx ("إنشاء أكواد الاشتراك") — decision 10.40.
            Route::post('/enrollment-codes', [EnrollmentCodeController::class, 'store'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_ENROLLMENT_CODES_MANAGE)
                ->name('enrollment-codes.store');

            Route::get('/enrollment-codes', [EnrollmentCodeController::class, 'index'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_ENROLLMENT_CODES_MANAGE)
                ->name('enrollment-codes.index');

            // Row 114 (open decision 10.13) — the literal '/search' segment
            // this section's own comment predicted, registered BEFORE the
            // '{code}' wildcard directly below so it cannot be swallowed by
            // it (exactly the hazard the comment above already named).
            // FilterEnrollmentCodes.tsx ("فلتر أكواد الاشتراك") — decision 10.40.
            Route::get('/enrollment-codes/search', [EnrollmentCodeController::class, 'search'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_FILTER_ENROLLMENT_CODES_VIEW)
                ->name('enrollment-codes.search');

            // EnrollmentCodeDetails.tsx ("تفاصيل كود الاشتراك") — decision 10.40.
            Route::get('/enrollment-codes/{code}', [EnrollmentCodeController::class, 'show'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_ENROLLMENT_CODE_DETAILS_VIEW)
                ->name('enrollment-codes.show');

            /*
             * ─── STAGE 10 — ROW 56, THE LAST /admin ROUTE IN THE TABLE. ────
             *
             * Session B's docblock on AdminDashboardController said this row
             * was Stage 10's and would not be stubbed. It is registered here
             * now, in its natural place beside the other dashboard reads.
             *
             * `role:admin` IS THE WHOLE RULE, like every other row in this
             * group. It reads the PLATFORM-WIDE login history — every
             * account's IP addresses and user agents — so it is one of the
             * most sensitive reads in the application, and the one place a
             * missing role guard would be least visible (the response is a
             * plain list with no ownership field to look wrong).
             *
             * NO RATE LIMIT, matching backend/. The query is a single
             * 300-row indexed read; the admin refreshes this page freely.
             */
            // LoginActivity ("مراقبة الدخول") — decision 10.40.
            Route::get('/login-activity', [AdminDashboardController::class, 'loginActivity'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_LOGIN_ACTIVITY_VIEW)
                ->name('login-activity');

            /*
             * Row 120, open decision 10.23 — companion admin read for the
             * public POST above. No filters, no pagination: a plain
             * newest-first list showing every submitted field, exactly as
             * requested.
             */
            Route::get('/teacher-applications', [TeacherApplicationController::class, 'index'])
                ->middleware('employee.permission:' . EmployeePermissions::ADMIN_TEACHER_APPLICATIONS_VIEW)
                ->name('teacher-applications.index');
        });

    /*
    |--------------------------------------------------------------------------
    | Enrollment-code redemption (Stage 7 — Session A) — 1 route
    |--------------------------------------------------------------------------
    |
    | Freeze-table row 60.
    |
    | ┌── `role:student` IS LOAD-BEARING, NOT TIDINESS. ─────────────────────┐
    | │ `subscriptions.student_id` is an FK to `students.user_id`, and       │
    | │ neither an admin nor an instructor owns a `students` row. Without    │
    | │ this middleware an admin's redemption would pass every check, reach   │
    | │ the INSERT and die on a raw foreign-key violation — a 500 where the   │
    | │ contract says 403. Same mechanism as open-decisions 3.1 and 5.13,    │
    | │ but resolved at the ROUTE here rather than inside the Action,        │
    | │ because unlike exam submission and lesson progress this endpoint has  │
    | │ NO legitimate non-student caller to keep serving.                     │
    | │                                                                      │
    | │ It is also why `Gate::before`'s admin bypass is irrelevant here:     │
    | │ middleware runs before the Gate is ever consulted, and no Policy is   │
    | │ consulted on this route at all.                                       │
    | └──────────────────────────────────────────────────────────────────────┘
    |
    | NO THROTTLE, matching backend/. A brute-force search of the 8-digit
    | space is not defended by a limiter on this route and never was; the real
    | mitigation is that a code is single-use and bound to one course. Adding
    | `throttle.lockout` here would be a new behaviour on a route the frontend
    | calls once per enrolment, and it is not this session's decision to make.
    */
    Route::middleware(['auth', 'active', 'role:student'])->group(function (): void {
        Route::post('/enrollment-codes/redeem', [EnrollmentCodeController::class, 'redeem'])
            ->name('enrollment-codes.redeem');
    });

    /*
    |--------------------------------------------------------------------------
    | Revenue (Stage 7 — Session B) — 13 routes
    |--------------------------------------------------------------------------
    |
    | Freeze-table rows 61-70 (admin) and 99-101 (the instructor's own three).
    |
    | ┌── THESE ARE **NOT** UNDER THE `/admin` PREFIX. VERIFIED, NOT ASSUMED. ┐
    | │ backend/routes/api.php registers them in their own group as bare      │
    | │ `/revenue/*` paths with `RoleMiddleware::for('admin')`, and its own    │
    | │ comment says why: "revenueService.ts calls these as bare /revenue/*    │
    | │ paths". Path prefix and authorisation are unrelated here. Folding them │
    | │ into the /admin group above for tidiness would 404 every finance       │
    | │ screen at once.                                                        │
    | └────────────────────────────────────────────────────────────────────────┘
    |
    | `role:admin` IS THE WHOLE RULE for the ten below, exactly as it is for
    | the admin block. No Policy is consulted and none should be added: each
    | would reduce to `return $user->isAdmin()`, which Gate::before answered
    | before any Policy method could be entered.
    |
    | ┌── ORDER-SENSITIVE, AND HERE IT IS "IN SPIRIT" ONLY. ──────────────────┐
    | │ `/revenue/transactions` and `/revenue/transactions/{teacherId}` differ │
    | │ by one segment, as do `/revenue/payouts` (POST) and                    │
    | │ `/revenue/payouts/{teacherId}` (GET), so no pair can collide today.    │
    | │ The literal-before-wildcard convention this file has kept since Stage  │
    | │ 4 is honoured anyway, so a future literal like                         │
    | │ `/revenue/transactions/recent` cannot be swallowed by `{teacherId}`.   │
    | └────────────────────────────────────────────────────────────────────────┘
    |
    | NO THROTTLE ON ANY OF THEM, matching backend/. These are admin reads plus
    | three low-frequency writes behind two role gates; adding a limiter would
    | be new behaviour on routes Revenue.tsx polls on every filter change.
    */
    Route::middleware(['auth', 'active', 'role:admin'])->group(function (): void {
        // Revenue.tsx ("إيرادات المنصة") — decision 10.40.
        Route::get('/revenue/platform', [RevenueController::class, 'platform'])
            ->middleware('employee.permission:' . EmployeePermissions::ADMIN_REVENUE_VIEW)
            ->name('revenue.platform');

        // SHARED between Revenue.tsx ("إيرادات المنصة") and RefundManagement.tsx
        // ("إدارة الاسترجاع" — lists transactions eligible for refund) — no single
        // ADMIN_NAV page owns it, left ungated beyond role:admin.
        Route::get('/revenue/transactions', [RevenueController::class, 'transactions'])
            ->name('revenue.transactions');

        // SHARED teacher picker across ProfitEngine.tsx, TeacherPayouts.tsx and
        // InstructorStatement.tsx (all three call revenueService.getRevenueTeachers()) —
        // no single ADMIN_NAV page owns it, left ungated beyond role:admin.
        Route::get('/revenue/teachers', [RevenueController::class, 'teachers'])
            ->name('revenue.teachers');

        // InstructorStatement.tsx ("كشف حساب مدرس") — decision 10.40.
        Route::get('/revenue/statement/{teacherId}', [RevenueController::class, 'teacherStatement'])
            ->middleware('employee.permission:' . EmployeePermissions::ADMIN_INSTRUCTOR_STATEMENT_VIEW)
            ->name('revenue.teacher-statement');

        Route::get('/revenue/transactions/{teacherId}', [RevenueController::class, 'teacherTransactions'])
            ->middleware('employee.permission:' . EmployeePermissions::ADMIN_INSTRUCTOR_STATEMENT_VIEW)
            ->name('revenue.teacher-transactions');

        // SHARED between TeacherPayouts.tsx and InstructorStatement.tsx (both call
        // revenueService.getTeacherPayouts()) — no single ADMIN_NAV page owns it, left
        // ungated beyond role:admin.
        Route::get('/revenue/payouts/{teacherId}', [RevenueController::class, 'teacherPayouts'])
            ->name('revenue.teacher-payouts');

        // ProfitEngine.tsx ("محرك الأرباح") — decision 10.40.
        Route::put('/revenue/teachers/{teacherId}/percentage', [RevenueController::class, 'updateTeacherPercentage'])
            ->middleware('employee.permission:' . EmployeePermissions::ADMIN_PROFIT_ENGINE_VIEW)
            ->name('revenue.teacher-percentage');

        // TeacherPayouts.tsx ("دفعات المدرسين") — decision 10.40.
        Route::post('/revenue/payouts', [RevenueController::class, 'createPayout'])
            ->middleware('employee.permission:' . EmployeePermissions::ADMIN_TEACHER_PAYOUTS_MANAGE)
            ->name('revenue.payouts.store');

        // RefundManagement.tsx ("إدارة الاسترجاع") — decision 10.40. Not currently
        // called by the frontend (RefundManagement.tsx never invokes
        // revenueService.getRefunds()), but this is the only ADMIN_NAV page the read
        // could conceivably belong to.
        Route::get('/revenue/refunds', [RevenueController::class, 'refunds'])
            ->middleware('employee.permission:' . EmployeePermissions::ADMIN_REFUND_MANAGEMENT_MANAGE)
            ->name('revenue.refunds.index');

        /*
         * ONE PATH, TWO OPERATIONS, discriminated by payload shape — 201 to
         * REQUEST a refund (`transactionId` present), 200 to PROCESS one
         * (`id` + `status`). backend/'s contract; refundApiService.ts posts
         * both here. See App\Http\Requests\Revenue\SubmitRefundRequest.
         */
        // RefundManagement.tsx ("إدارة الاسترجاع") — decision 10.40.
        Route::post('/revenue/refunds', [RevenueController::class, 'submitRefund'])
            ->middleware('employee.permission:' . EmployeePermissions::ADMIN_REFUND_MANAGEMENT_MANAGE)
            ->name('revenue.refunds.submit');
    });

    /*
    | ┌── `role:instructor` WITH **NO ADMIN OVERRIDE**. ROWS 99, 100, 101. ───┐
    | │ Matching backend/'s own group and the self-service pattern Stage 4    │
    | │ established for /teachers/statistics and /students/dashboard: an      │
    | │ ADMIN CALLING THESE GETS 403, and that is correct rather than         │
    | │ inconvenient. An admin has no revenue of their own, so "my statement" │
    | │ has no meaning for them — and rows 64/65/66 already read ANY          │
    | │ teacher's figures by id, so nothing is out of reach.                  │
    | │                                                                       │
    | │ `Gate::before`'s admin bypass cannot reopen this: middleware runs     │
    | │ before any Gate is consulted, and no Policy is consulted here at all. │
    | └───────────────────────────────────────────────────────────────────────┘
    |
    | THE TEACHER IS THE SESSION, ALWAYS. A `?teacher_id=` on any of the three
    | is inert BY CONSTRUCTION — the controller methods read
    | `$request->user()->getKey()` and no code path beneath them reads the
    | query string. Nothing to validate means nothing to get wrong.
    */
    Route::middleware(['auth', 'active', 'role:instructor', 'employee.permission:' . EmployeePermissions::REVENUE_VIEW])
        ->group(function (): void {
            Route::get('/revenue/my-statement', [RevenueController::class, 'myStatement'])
                ->name('revenue.my-statement');

            Route::get('/revenue/my-transactions', [RevenueController::class, 'myTransactions'])
                ->name('revenue.my-transactions');

            Route::get('/revenue/my-payouts', [RevenueController::class, 'myPayouts'])
                ->name('revenue.my-payouts');
        });

    /*
    |--------------------------------------------------------------------------
    | Courses, sections, groups (Stage 5 — Session A) — 16 routes
    |--------------------------------------------------------------------------
    |
    | Freeze-table rows 8, 9, 11-19, 31, 41 and 74-77. Row 41 is registered
    | inside the admin group above, where its path lives.
    |
    | ┌── WHY THE COURSE ROUTES USE POLICIES AND THE STAGE 4 ROUTES DID NOT ─┐
    | │ Stage 4's 19 routes are either `role:admin` (a Policy could only say  │
    | │ `return $user->isAdmin()`, which Gate::before already answered) or    │
    | │ self-service (no resource the caller NAMES). Both reasons evaporate   │
    | │ here: `/courses/{id}` names a row, and the answer depends on WHOSE    │
    | │ row it is. CoursePolicy, SectionPolicy and CourseGroupPolicy are the  │
    | │ authority; App\Support\CourseAccess is the thin HTTP layer that turns │
    | │ their verdicts into the exact statuses and Arabic/English strings the │
    | │ freeze table pins.                                                    │
    | └──────────────────────────────────────────────────────────────────────┘
    |
    | ┌── THE FOUR PUBLIC ROUTES BELOW MUST NEVER GAIN `auth`. ──────────────┐
    | │ `/courses`, `/courses/{id}` and `/courses/{courseId}/sections` are    │
    | │ DEGRADE rows: the storefront renders them logged-out on every course  │
    | │ page load. Adding `auth` is not a tightening, it is an outage. The    │
    | │ filtering happens in the QUERY (Course::scopePublished) and in        │
    | │ CourseAccess::assertVisible(), which answers 404 — never 403, and     │
    | │ never `$this->authorize()`, which the freeze table forbids in bold on │
    | │ every DEGRADE row.                                                    │
    | └──────────────────────────────────────────────────────────────────────┘
    */

    Route::get('/courses', [CourseController::class, 'index'])->name('courses.index');

    /*
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ ORDER-SENSITIVE, AND THIS ONE **CAN** ACTUALLY COLLIDE.             ║
     * ║                                                                      ║
     * ║ `/courses/my-courses` MUST be registered before `/courses/{id}`.     ║
     * ║ Both are two-segment paths and `{id}` matches any single segment, so ║
     * ║ the literal string "my-courses" is swallowed by the wildcard if the  ║
     * ║ wildcard is first — backend/ hit exactly this and answered a         ║
     * ║ misleading 'Course not found' 404 (its router comment says so). This ║
     * ║ is unlike the /admin/teachers pair above, where the segment counts   ║
     * ║ differ and the ordering is convention only.                          ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * MULTI-ROLE: no `role:` middleware at all, matching backend/'s bare
     * AuthMiddleware. The three branches live in the controller because they
     * return three different SETS, not three different permissions.
     */
    Route::middleware(['auth', 'active'])->group(function (): void {
        Route::get('/courses/my-courses', [CourseController::class, 'myCourses'])
            ->name('courses.my-courses');
    });

    Route::get('/courses/{id}', [CourseController::class, 'show'])->name('courses.show');

    /*
     * GET /courses/{id}/structure — PUBLIC. Row 10, Stage 5 / Session C.
     *
     * The course table of contents, rendered for logged-out visitors on every
     * course page load. NO `detail` payload for anybody — see
     * App\Http\Resources\CourseStructureResource. Decision 3.6 cascades: a
     * draft course's outline is 404 for anyone but its owner and an admin.
     *
     * Three segments, so it cannot collide with `/courses/{id}` or
     * `/courses/my-courses`; registered adjacent to its sibling regardless.
     */
    Route::get('/courses/{id}/structure', [CourseController::class, 'structure'])
        ->name('courses.structure');

    /*
     * GET /codes/student-courses — a LEGACY ALIAS of /courses/my-courses,
     * student-only. There is no `codes` table anywhere in this schema; the
     * path is an old name for the enrolment list and BOTH paths are live in
     * the shipped frontend. Registered separately and pointed at a handler
     * that delegates, so the two can never disagree. Do not consolidate.
     */
    Route::middleware(['auth', 'active', 'role:student'])->group(function (): void {
        Route::get('/codes/student-courses', [CourseController::class, 'studentCourses'])
            ->name('codes.student-courses');
    });

    /*
     * COURSE WRITES. `role:admin,instructor` is the coarse gate; ownership is
     * the fine one and lives in the Form Requests' authorize(), which runs
     * BEFORE validation so a foreign instructor never receives a 422
     * describing someone else's course. See UpdateCourseRequest.
     */
    Route::middleware(['auth', 'active', 'role:admin,instructor', 'employee.permission:' . EmployeePermissions::COURSES_MANAGE])
        ->group(function (): void {
            Route::post('/courses', [CourseController::class, 'store'])->name('courses.store');
            Route::put('/courses/{id}', [CourseController::class, 'update'])->name('courses.update');
            Route::delete('/courses/{id}', [CourseController::class, 'destroy'])->name('courses.destroy');
        });

    /*
     * SECTIONS. The public read carries decision 3.6's cascade: a draft
     * course's syllabus is 404 for anyone but its owner and an admin.
     *
     * ORDERING NOTE: '/courses/{courseId}/sections/reorder' is a PATCH and
     * '/courses/{courseId}/sections' is a POST, so the two cannot collide on
     * method+path. The reorder route is nevertheless written adjacent to its
     * siblings rather than beside the course routes, because its subject is a
     * section list.
     */
    Route::get('/courses/{courseId}/sections', [SectionController::class, 'index'])
        ->name('courses.sections.index');

    Route::middleware(['auth', 'active', 'role:admin,instructor', 'employee.permission:' . EmployeePermissions::COURSES_MANAGE])
        ->group(function (): void {
            Route::post('/courses/{courseId}/sections', [SectionController::class, 'store'])
                ->name('courses.sections.store');
            Route::put('/sections/{id}', [SectionController::class, 'update'])
                ->name('sections.update');
            Route::patch('/courses/{courseId}/sections/reorder', [SectionController::class, 'reorder'])
                ->name('courses.sections.reorder');
            Route::delete('/sections/{id}', [SectionController::class, 'destroy'])
                ->name('sections.destroy');
        });

    /*
     * COURSE GROUPS. All four are `auth`,`role:admin,instructor` — there is no
     * public group surface. `GET /groups` is a LIST SCOPE (instructor sees
     * their own, admin sees all), not an ability; see GroupController::index.
     */
    Route::middleware(['auth', 'active', 'role:admin,instructor', 'employee.permission:' . EmployeePermissions::GROUPS_MANAGE])
        ->group(function (): void {
            Route::get('/groups', [GroupController::class, 'index'])->name('groups.index');
            Route::post('/groups', [GroupController::class, 'store'])->name('groups.store');
            Route::patch('/groups/{id}', [GroupController::class, 'update'])->name('groups.update');
            Route::delete('/groups/{id}', [GroupController::class, 'destroy'])->name('groups.destroy');
        });

    /*
    |--------------------------------------------------------------------------
    | Content items (Stage 5 — Sessions B and C) — 9 routes
    |--------------------------------------------------------------------------
    |
    | ╔══════════════════════════════════════════════════════════════════════╗
    | ║ SESSION C OPENED THE THREE PUBLIC READS THAT SESSION B DELIBERATELY ║
    | ║ LEFT UNREGISTERED. THE GUARANTEE CHANGED SHAPE — READ THIS BEFORE   ║
    | ║ ADDING ANYTHING TO THE PUBLIC BLOCK BELOW.                           ║
    | ║                                                                      ║
    | ║   row 20  GET /sections/{sectionId}/content-items   DEGRADE          ║
    | ║   row 21  GET /content-items/{id}                   404 then DEGRADE ║
    | ║   row 22  GET /courses/{courseId}/videos            DEGRADE          ║
    | ║                                                                      ║
    | ║ Until this session the guarantee was "there is no handler, so no     ║
    | ║ payload decision can be got wrong". It is now "there are two         ║
    | ║ structurally different Resource classes, and the one served to the   ║
    | ║ un-entitled has no code path capable of emitting a paid field".      ║
    | ║ That is weaker than absence and stronger than a conditional, which   ║
    | ║ is why PublicContentItemResource is a separate class and why         ║
    | ║ PublicPayloadLeakTest asserts the absence of every forbidden key     ║
    | ║ against all six content types.                                       ║
    | ║                                                                      ║
    | ║ THESE THREE MUST NEVER GAIN `auth`. They render on the logged-out    ║
    | ║ storefront; adding it is an outage, not a tightening. Equally,       ║
    | ║ `$this->authorize()` is forbidden on all three (DEGRADE rows) and    ║
    | ║ the entitlement idiom is `Gate::forUser($request->user())->allows()` ║
    | ║ — never `?->can()`, open decision 3.7.                               ║
    | ║                                                                      ║
    | ║ The one READ that stays behind `auth` (row 78) is admin/instructor   ║
    | ║ in the FINAL contract too — it was never public. See                 ║
    | ║ App\Http\Requests\ContentItem\ListGroupContentItemsRequest.          ║
    | ╚══════════════════════════════════════════════════════════════════════╝
    |
    | ORDERING NOTE: '/sections/{sectionId}/content-items/reorder' is a PATCH
    | and '/sections/{sectionId}/content-items' is a POST, so the two cannot
    | collide on method+path — the same situation as the section reorder.
    | The public GET on '/sections/{sectionId}/content-items' shares its path
    | with the POST but not its verb, so it likewise cannot collide.
    */
    Route::get('/sections/{sectionId}/content-items', [ContentItemController::class, 'index'])
        ->name('sections.content-items.index');
    Route::get('/content-items/{id}', [ContentItemController::class, 'show'])
        ->name('content-items.show');
    Route::get('/courses/{courseId}/videos', [ContentItemController::class, 'indexVideosByCourse'])
        ->name('courses.videos.index');

    Route::middleware(['auth', 'active', 'role:admin,instructor'])->group(function (): void {
        Route::post('/sections/{sectionId}/content-items', [ContentItemController::class, 'store'])
            ->name('sections.content-items.store');
        Route::patch('/sections/{sectionId}/content-items/reorder', [ContentItemController::class, 'reorder'])
            ->name('sections.content-items.reorder');
        Route::put('/content-items/{id}', [ContentItemController::class, 'update'])
            ->name('content-items.update');
        Route::delete('/content-items/{id}', [ContentItemController::class, 'destroy'])
            ->name('content-items.destroy');

        Route::get('/groups/{groupId}/content-items', [ContentItemController::class, 'indexByGroup'])
            ->name('groups.content-items.index');
        Route::post('/groups/{groupId}/content-items', [ContentItemController::class, 'storeByGroup'])
            ->name('groups.content-items.store');
    });

    /*
    |--------------------------------------------------------------------------
    | Lessons — video + progress (Stage 5 — Session C) — 3 routes
    |--------------------------------------------------------------------------
    |
    | Freeze-table rows 27, 28 and 29. Backs VideoPlayerPage.tsx.
    |
    | ╔══════════════════════════════════════════════════════════════════════╗
    | ║ THE **NOT**-PUBLIC HALF OF SESSION C, AND THE CONTRAST IS DELIBERATE.║
    | ║                                                                      ║
    | ║ The three routes above hand a guest a REDUCED payload. These hand    ║
    | ║ out `videos.video_url` — the paid product itself — so there is no    ║
    | ║ reduced shape to degrade to and `auth` is mandatory. Row 27 exists   ║
    | ║ for precisely this: without it, knowing a content-item UUID would   ║
    | ║ hand out the stream URL.                                             ║
    | ║                                                                      ║
    | ║ NO `role:` MIDDLEWARE — matching backend/'s bare AuthMiddleware.     ║
    | ║ That is load-bearing twice over: a free-preview lesson               ║
    | ║ (`videos.is_preview = 1`) is open to ANY authenticated user          ║
    | ║ regardless of role or subscription (open decision 3.8, resolved in   ║
    | ║ ContentItemPolicy::watch), and an instructor legitimately opens the  ║
    | ║ player to review their own material. A `role:student` gate would     ║
    | ║ break both.                                                          ║
    | ╚══════════════════════════════════════════════════════════════════════╝
    |
    | GET|POST on the same progress path: registered as two routes rather than
    | Route::match, because they run different code — a read and a write with
    | its own Action — unlike the PATCH/PUT teacher pair which genuinely share
    | one handler.
    |
    | `api/v1/lessons/*` is deliberately NOT on AppServiceProvider::
    | preserveEmptyStringsOnClearableWrites(): both progress fields are numeric
    | and '' is indistinguishable from 0 on either side of the middleware.
    | Checked against backend/, not assumed — see SaveLessonProgress.
    */
    Route::middleware(['auth', 'active'])->group(function (): void {
        Route::get('/lessons/{id}/video', [LessonController::class, 'video'])
            ->name('lessons.video');
        Route::get('/lessons/{id}/progress', [LessonController::class, 'progress'])
            ->name('lessons.progress.show');
        Route::post('/lessons/{id}/progress', [LessonController::class, 'saveProgress'])
            ->name('lessons.progress.store');
    });

    /*
    |--------------------------------------------------------------------------
    | Question bank (Stage 6 — Session A) — 8 routes
    |--------------------------------------------------------------------------
    |
    | Freeze-table rows 80-87. All eight are `auth`,`active`,
    | `role:admin,instructor` — there is no public question-bank surface, and
    | unlike rows 20-22 that is true of the FINAL contract too, not just of this
    | session. A question bank is an instructor's private working material and
    | nothing logged-out renders it.
    |
    | ┌── THE ONE OWNERSHIP CHAIN WITH NO COURSE IN IT. ────────────────────┐
    | │ `question_groups.teacher_id` -> `teachers.user_id`, directly. That   │
    | │ is why App\Policies\QuestionGroupPolicy is the single policy that    │
    | │ does not use ResolvesCourseOwnership, and why `GET /question-groups` │
    | │ is a LIST SCOPE (own banks / all banks) rather than an ability.      │
    | └──────────────────────────────────────────────────────────────────────┘
    |
    | ORDERING NOTE: '/question-groups/{id}' and '/question-groups/{groupId}/
    | questions' differ in segment count, and '/questions/{id}' is a different
    | first segment entirely, so nothing here can collide.
    */
    Route::middleware(['auth', 'active', 'role:admin,instructor'])->group(function (): void {
        Route::get('/question-groups', [QuestionBankController::class, 'indexGroups'])
            ->middleware('employee.permission:' . EmployeePermissions::QUESTION_GROUPS_MANAGE)
            ->name('question-groups.index');
        Route::post('/question-groups', [QuestionBankController::class, 'storeGroup'])
            ->middleware('employee.permission:' . EmployeePermissions::QUESTION_GROUPS_MANAGE)
            ->name('question-groups.store');
        Route::patch('/question-groups/{id}', [QuestionBankController::class, 'updateGroup'])
            ->middleware('employee.permission:' . EmployeePermissions::QUESTION_GROUPS_MANAGE)
            ->name('question-groups.update');
        Route::delete('/question-groups/{id}', [QuestionBankController::class, 'destroyGroup'])
            ->middleware('employee.permission:' . EmployeePermissions::QUESTION_GROUPS_MANAGE)
            ->name('question-groups.destroy');

        Route::get('/question-groups/{groupId}/questions', [QuestionBankController::class, 'indexQuestions'])
            ->middleware('employee.permission:' . EmployeePermissions::QUESTION_BANK_MANAGE)
            ->name('question-groups.questions.index');
        Route::post('/question-groups/{groupId}/questions', [QuestionBankController::class, 'storeQuestion'])
            ->middleware('employee.permission:' . EmployeePermissions::QUESTION_BANK_MANAGE)
            ->name('question-groups.questions.store');
        Route::patch('/questions/{id}', [QuestionBankController::class, 'updateQuestion'])
            ->middleware('employee.permission:' . EmployeePermissions::QUESTION_BANK_MANAGE)
            ->name('questions.update');
        Route::delete('/questions/{id}', [QuestionBankController::class, 'destroyQuestion'])
            ->middleware('employee.permission:' . EmployeePermissions::QUESTION_BANK_MANAGE)
            ->name('questions.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Exams — READ ONLY (Stage 6 — Session A) — 2 routes
    |--------------------------------------------------------------------------
    |
    | Freeze-table rows 88 and 89. BOTH ARE PUBLIC.
    |
    | ╔══════════════════════════════════════════════════════════════════════╗
    | ║ ⚠ THE MOST IMPORTANT FACT ABOUT THIS BLOCK IS WHAT IS **NOT** IN IT. ║
    | ║                                                                      ║
    | ║ There is NO route on `exam_attempts` in this session. Not a submit,   ║
    | ║ not a grade, not a results read. Rows 90, 91 and 92 are ALL Stage     ║
    | ║ 6 / Session B.                                                        ║
    | ║                                                                      ║
    | ║ That is a deliberate scope boundary, and it is the same technique     ║
    | ║ Sessions 5A and 5B used to make a payload leak impossible: cheating   ║
    | ║ the attempt cap is impossible BY CONSTRUCTION until Session B exists, ║
    | ║ because there is no handler whose locking could be got wrong. Session ║
    | ║ B owns the row lock, the count-then-insert critical section and the   ║
    | ║ UNIQUE(exam_id, student_id, attempt_number) ordinal.                  ║
    | ║                                                                      ║
    | ║ Pinned by ExamAuthGateTest::test_no_attempt_or_submission_write_route ║
    | ║ _exists_yet, which asserts 404 FROM THE ROUTER (not 401 from a guard) ║
    | ║ on the Session B paths. THAT TEST IS EXPECTED TO FAIL WHEN            ║
    | ║ SESSION B LANDS — deleting it must be a deliberate act accompanied by ║
    | ║ the concurrency tests that replace it.                                ║
    | ╚══════════════════════════════════════════════════════════════════════╝
    |
    | ┌── THESE TWO MUST NEVER GAIN `auth`. APPROVED DECISION #1. ───────────┐
    | │ They render on the logged-out course page, exactly like /courses/{id} │
    | │ and its /structure and /sections siblings, and backend/ registers     │
    | │ them outside its AuthMiddleware group. Adding `auth` is not a         │
    | │ tightening, it is an outage.                                          │
    | │                                                                      │
    | │ What was added instead, and what makes the middle ground work:        │
    | │  (a) `is_hidden` FILTERING on the listing — backend/ has none,        │
    | │      so a guest could enumerate an instructor's staged exams;         │
    | │  (b) decision 3.6's DRAFT-COURSE CASCADE on both — a draft            │
    | │      course's exams 404 for a non-owner;                              │
    | │  (c) the THREE-WAY RESOURCE SPLIT, which is what actually governs     │
    | │      which fields ship — so the payload is safe whether or not the    │
    | │      route is gated.                                                  │
    | │                                                                      │
    | │ And the DEGRADE rules apply in full: never `$this->authorize()` on    │
    | │ these two, and always `Gate::forUser($request->user())->allows(...)` │
    | │ rather than `?->can()` (open decision 3.7).                           │
    | └──────────────────────────────────────────────────────────────────────┘
    |
    | ORDERING NOTE: '/courses/{courseId}/exams' is three segments and cannot
    | collide with '/courses/{id}' or '/courses/my-courses'; '/exams/{examId}'
    | is a new first segment.
    */
    Route::get('/courses/{courseId}/exams', [ExamController::class, 'indexByCourse'])
        ->name('courses.exams.index');
    Route::get('/exams/{examId}', [ExamController::class, 'show'])
        ->name('exams.show');

    /*
    |--------------------------------------------------------------------------
    | Exams — ATTEMPTS AND RESULTS (Stage 6 — Session B) — 3 routes
    |--------------------------------------------------------------------------
    |
    | Freeze-table rows 90, 91 and 92. THIS COMPLETES STAGE 6.
    |
    | ╔══════════════════════════════════════════════════════════════════════╗
    | ║ ⚠ THE SCOPE BOUNDARY SESSION A DECLARED IS DELIBERATELY CROSSED HERE.║
    | ║                                                                      ║
    | ║ Session A registered NO route on `exam_attempts`, which made          ║
    | ║ cheating the attempt cap                                              ║
    | ║ impossible BY CONSTRUCTION — there was no handler whose locking could ║
    | ║ be got wrong. That guarantee is spent, on purpose, and                ║
    | ║ AssessmentAuthGateTest's "these must not exist" assertions were       ║
    | ║ REPLACED (not deleted) by tests/Concurrency/*.php, exactly as the     ║
    | ║ hand-off required.                                                    ║
    | ║                                                                      ║
    | ║ What holds the guarantee now:                                         ║
    | ║  * ONE writer per table — App\Actions\Exam\SubmitExamAttempt,         ║
    | ║    nothing else;                                                      ║
    | ║  * it performs count AND insert inside ONE transaction holding an     ║
    | ║    exclusive lock on the PARENT row (`exams`), never                  ║
    | ║    on the child rows — a first attempt has no child row to lock;      ║
    | ║  * `uq_exam_attempts_ordinal` below the application;                  ║
    | ║  * proven by 8 genuinely parallel OS processes plus an unlocked       ║
    | ║    control that MUST breach the cap, or the harness proves nothing.   ║
    | ╚══════════════════════════════════════════════════════════════════════╝
    |
    | ┌── `rl:submit` GOES ON THE WRITE, AND IT IS **NOT** THE CAP. ─────────┐
    | │ backend/ says so in its own router comment: the throttle is spam      │
    | │ defence only — 10/min, escalating lockout (config/ratelimit.php's     │
    | │ `submit` bucket, declared since Stage 1 for exactly this session).    │
    | │ The business rule is `allowed_attempts`, enforced under the row lock. │
    | │ Neither replaces the other: a limiter is per-caller and per-window,   │
    | │ the cap is per (student, exam) and forever.                           │
    | │                                                                      │
    | │ ORDERING: ThrottleWithLockout is on the priority list AHEAD of `auth` │
    | │ (bootstrap/app.php), so abusive traffic is rejected before the        │
    | │ per-request user lookup — the same arrangement as                     │
    | │ /auth/update-credentials.                                             │
    | └──────────────────────────────────────────────────────────────────────┘
    |
    | ┌── WHY ROWS 90/91 CARRY NO `role:` MIDDLEWARE. ───────────────────────┐
    | │ Row 90 is ALSO the instructor's read-only exam preview and row 91 is  │
    | │ how staff read a student's result, so `role:student` would break      │
    | │ both.                                                                 │
    | │ The identity rule that DOES matter — "only a real `students` row may  │
    | │ record an attempt" — is enforced in the Actions, because              │
    | │ `Gate::before` waves admins past any middleware-adjacent policy check.│
    | └──────────────────────────────────────────────────────────────────────┘
    */
    Route::middleware(['auth', 'active'])->group(function (): void {
        Route::get('/exams/{examId}/questions', [ExamController::class, 'questions'])
            ->name('exams.questions');
        Route::get('/exams/{examId}/results', [ExamController::class, 'results'])
            ->name('exams.results');
    });

    Route::middleware(['throttle.lockout:submit', 'auth', 'active'])->group(function (): void {
        Route::post('/exams/{examId}/submit', [ExamController::class, 'submit'])
            ->name('exams.submit');
    });

    /*
    |--------------------------------------------------------------------------
    | "إحصائيات الامتحانات" / "إحصائيات المشاهدات" — decision 10.36.
    |--------------------------------------------------------------------------
    | Instructor-only, OWNER-only (see ExamStatisticsController's/
    | VideoStatisticsController's banners for why `role:instructor` here is
    | narrower than every existing exam/content-item read above — those admit
    | an enrolled student too).
    */
    Route::middleware(['auth', 'active', 'role:instructor'])->group(function (): void {
        Route::get('/exams/{examId}/statistics', [ExamStatisticsController::class, 'show'])
            ->middleware('employee.permission:' . EmployeePermissions::EXAM_STATISTICS_VIEW)
            ->name('exams.statistics');
        Route::get('/content-items/{id}/watch-statistics', [VideoStatisticsController::class, 'show'])
            ->middleware('employee.permission:' . EmployeePermissions::WATCH_STATISTICS_VIEW)
            ->name('content-items.watch-statistics');
    });

    /*
    |--------------------------------------------------------------------------
    | "إدارة الأدمنز" — decisions 10.37 + 10.40. ONE route family serves BOTH
    | a teacher managing their own Employee/Amin sub-accounts (10.37) AND an
    | admin managing THEIR OWN, isolated-per-admin sub-accounts (10.40,
    | "موظفو الأدمن منفصلون عن المدرّسين كلياً") — `role:admin,instructor`,
    | never split into two controllers/route sets, because
    | `Employee::scopeOwnedBy($request->user())` already scopes correctly
    | for either caller with no branching (see EmployeeController's banner).
    | `employee.permission:admin_management.manage` on the WHOLE group: a
    | real teacher/admin/Amin always passes (see EnsureEmployeePermission's
    | banner); a limited Employee needs this permission explicitly, and
    | App\Support\EmployeePrivilegeCeiling (consulted inside the Actions, not
    | here) is what then stops that Employee from escalating themselves or
    | touching another Amin/the owner once inside.
    |--------------------------------------------------------------------------
    */
    Route::middleware([
        'auth', 'active', 'role:admin,instructor',
        'employee.permission:' . EmployeePermissions::ADMIN_MANAGEMENT_MANAGE,
    ])->group(function (): void {
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::put('/employees/{id}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::delete('/employees/{id}', [EmployeeController::class, 'destroy'])->name('employees.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | STAGE 8 — NOTIFICATIONS. Freeze-table rows 102-112.
    |--------------------------------------------------------------------------
    |
    | Three surfaces that share a subject and share nothing else:
    |
    |   PERSONAL INBOX      /notifications              any authenticated role
    |   ADMIN BROADCASTS    /admin/broadcasts           role:admin
    |   ANNOUNCEMENTS       /announcements              role:admin,instructor
    |
    | ┌── THE INBOX ROUTES CARRY NO `role:` AND NEED NO POLICY. ─────────────┐
    | │ Every authenticated role has an inbox, so there is no role to filter │
    | │ on — and the OWNERSHIP question is answered by the query itself      │
    | │ (`$request->user()->notifications()`), not by an ability. Approved   │
    | │ decision 3.3, argued in full on NotificationController. This is the  │
    | │ same reasoning as the self-service profile routes in Stages 4 and 7: │
    | │ the acting identity comes from the session and nothing in the        │
    | │ request body can widen it.                                           │
    | └──────────────────────────────────────────────────────────────────────┘
    |
    | ┌── THE BROADCAST **GET** IS SPLIT OUT OF THE WRITE GROUP DELIBERATELY.┐
    | │ It is `role:admin` like the writes — the list reveals who every past │
    | │ broadcast targeted, so read secrecy is the point — but it carries NO │
    | │ rate limiter. backend/routes/api.php makes exactly the same split    │
    | │ (two groups, one with RateLimitMiddleware::for('broadcast') and one  │
    | │ without), and the reason is that the admin screen re-reads this list │
    | │ after every mutation: throttling it would lock an admin out of their │
    | │ own audit view for up to 30 minutes over a read.                     │
    | │                                                                      │
    | │ `throttle.lockout:broadcast` is 10 per 5 min with a 5min -> 30min    │
    | │ escalating block (config/ratelimit.php, declared in Stage 1 for this │
    | │ exact session). ORDERING: ThrottleWithLockout is on the priority     │
    | │ list AHEAD of `auth` (bootstrap/app.php), so abusive traffic is      │
    | │ rejected before the per-request user lookup — the same arrangement   │
    | │ as /auth/update-credentials and the submit routes above.            │
    | │                                                                      │
    | │ THE BUCKET IS KEYED ON THE ACTING ADMIN'S USER ID, not the IP, so    │
    | │ one careless admin session cannot lock out the rest of the staff and │
    | │ a shared office NAT does not pool every admin into one counter.      │
    | └──────────────────────────────────────────────────────────────────────┘
    |
    | ┌── ANNOUNCEMENTS ARE `role:admin,instructor`, OWNERSHIP UNDERNEATH. ──┐
    | │ The coarse gate is the middleware; per-row ownership is              │
    | │ AnnouncementPolicy::manage, asked by the Form Requests. An ADMIN     │
    | │ posting here creates a PLATFORM announcement (teacher_id NULL) —     │
    | │ approved decision 3.2, and the fix for a live 500 in backend/.       │
    | │                                                                      │
    | │ PATCH, not PUT: the update is genuinely partial, unlike the          │
    | │ broadcast PUT directly above, which is a full replacement. The verbs │
    | │ are backend/'s and they mean what they say.                          │
    | └──────────────────────────────────────────────────────────────────────┘
    */

    Route::middleware(['auth', 'active'])->group(function (): void {
        Route::get('/notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');
        Route::post('/notifications/{id}/mark-read', [NotificationController::class, 'markRead'])
            ->name('notifications.mark-read');
    });

    // BroadcastNotifications.tsx ("بث الإشعارات العامة") — decision 10.40.
    Route::middleware([
        'auth', 'active', 'role:admin',
        'employee.permission:' . EmployeePermissions::ADMIN_BROADCAST_MANAGE,
    ])->group(function (): void {
        Route::get('/admin/broadcasts', [BroadcastNotificationController::class, 'index'])
            ->name('admin.broadcasts.index');
    });

    Route::middleware([
        'throttle.lockout:broadcast', 'auth', 'active', 'role:admin',
        'employee.permission:' . EmployeePermissions::ADMIN_BROADCAST_MANAGE,
    ])->group(function (): void {
        Route::post('/admin/broadcasts', [BroadcastNotificationController::class, 'store'])
            ->name('admin.broadcasts.store');
        Route::put('/admin/broadcasts/{id}', [BroadcastNotificationController::class, 'update'])
            ->name('admin.broadcasts.update');
        Route::patch('/admin/broadcasts/{id}/toggle', [BroadcastNotificationController::class, 'toggle'])
            ->name('admin.broadcasts.toggle');
        Route::delete('/admin/broadcasts/{id}', [BroadcastNotificationController::class, 'destroy'])
            ->name('admin.broadcasts.destroy');
    });

    Route::middleware(['auth', 'active', 'role:admin,instructor'])->group(function (): void {
        Route::get('/announcements', [AnnouncementController::class, 'index'])
            ->name('announcements.index');
        Route::post('/announcements', [AnnouncementController::class, 'store'])
            ->name('announcements.store');
        Route::patch('/announcements/{id}', [AnnouncementController::class, 'update'])
            ->name('announcements.update');
        Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy'])
            ->name('announcements.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Uploads and file serving (Stage 9) — 3 routes
    |--------------------------------------------------------------------------
    |
    | Freeze-table rows 71, 72 and 73 — the whole files family, and the last
    | three rows outside the settings/admin surface Stage 10 owns.
    |
    | ╔══════════════════════════════════════════════════════════════════════╗
    | ║ THESE THREE ROUTES ARE THE ONLY DOORS INTO storage/uploads, AND THAT ║
    | ║ IS THE SECURITY PROPERTY. The directory sits OUTSIDE the web-server  ║
    | ║ document root (public/ is the root), so Apache/Nginx has no URL that ║
    | ║ maps to it. One writer, two readers, every one of them through PHP.  ║
    | ║                                                                      ║
    | ║ NEVER run `php artisan storage:link` — see config/filesystems.php.   ║
    | ╚══════════════════════════════════════════════════════════════════════╝
    |
    | ┌── ROW 71: THE LIMITER IS LISTED **BEFORE** `auth`, DELIBERATELY. ────┐
    | │ An upload costs a disk write and a finfo read of real bytes before   │
    | │ anything can be decided about it, so a flood must be refused as      │
    | │ cheaply as possible — BEFORE the per-request user lookup, not after. │
    | │ backend/ made the same choice and said so in a comment.              │
    | │                                                                      │
    | │ Listing it first is NOT sufficient on its own: Laravel's middleware  │
    | │ PRIORITY LIST reorders `auth` ahead of throttling by default, which  │
    | │ is why bootstrap/app.php prepends ThrottleWithLockout to that list.  │
    | │ Measured in Stage 1 on /auth/update-credentials, where the array     │
    | │ order alone produced 401-forever and the limiter never ran.          │
    | │                                                                      │
    | │ CONSEQUENCE, and it is the reason the bucket keys the way it does:   │
    | │ the limiter runs before the user is resolved, so `user:{id}` is      │
    | │ available only when the session was already established earlier in   │
    | │ the stack, and `ip:{addr}` is the fallback. ThrottleWithLockout::    │
    | │ callerKey() has done exactly that since Stage 1 and is reused here   │
    | │ unchanged — including its escalating lockout, which is why 21 rapid  │
    | │ uploads answer 429 with a growing Retry-After rather than a flat     │
    | │ one-minute window.                                                    │
    | └──────────────────────────────────────────────────────────────────────┘
    |
    | `active` accompanies `auth` on both authenticated rows even though the
    | freeze table's middleware column says only `auth`: that column is Stage
    | 3 shorthand for backend/'s AuthMiddleware, which re-read the user row and
    | rejected `is_active = 0` / soft-deleted itself. v2 split that one class
    | into two middlewares, so `auth`,`active` IS the faithful port and `auth`
    | alone would be a REGRESSION — a deactivated instructor would keep
    | uploading and downloading until their cookie expired.
    */
    Route::middleware(['throttle.lockout:upload', 'auth', 'active'])->group(function (): void {
        Route::post('/uploads', [UploadController::class, 'store'])->name('uploads.store');
    });

    /*
    | ┌── ROW 72: GENUINELY PUBLIC, AND IT STAYS INSIDE THE SESSION GROUP. ──┐
    | │ No `auth`. Teacher avatars/banners, course banners, question images  │
    | │ and video thumbnails render for logged-out visitors on the           │
    | │ storefront; adding `auth` here is an outage, not a tightening.       │
    | │                                                                      │
    | │ It sits inside routes/api.php's `api` group like every other route,  │
    | │ which means StartSession runs and every public image request touches │
    | │ session state and ships a Set-Cookie. backend/ has exactly the same  │
    | │ shape. Moving it out would be a BEHAVIOUR CHANGE (a caching proxy    │
    | │ sees a different response), so it is preserved as-is and recorded as │
    | │ open decision 9.4 — an optimisation opportunity, not a bug.          │
    | └──────────────────────────────────────────────────────────────────────┘
    |
    | ╔══════════════════════════════════════════════════════════════════════╗
    | ║ **NO `->whereUuid('id')` AND NO `->where('category', …)` HERE.**     ║
    | ║ A ROUTE CONSTRAINT WOULD BE A SILENT SECURITY REGRESSION.            ║
    | ║                                                                      ║
    | ║ It would 404 in the ROUTER, before the handler ran — and every       ║
    | ║ rejection would stop being LOGGED. The distinct reasons              ║
    | ║ ('category_not_public', 'invalid_file_id', 'not_found') are the      ║
    | ║ product of this endpoint for an operator: someone probing for path   ║
    | ║ traversal is visible only as a rising `invalid_file_id` count. The   ║
    | ║ wire answer is identical either way, so the constraint would buy     ║
    | ║ nothing and cost the entire audit trail.                             ║
    | ║                                                                      ║
    | ║ The validation lives in App\Support\StoredUploadPath::resolvePublic. ║
    | ╚══════════════════════════════════════════════════════════════════════╝
    */
    Route::get('/files/{category}/{id}', [FileController::class, 'showPublic'])
        ->name('files.show');

    /*
    | ┌── ROW 73: ADDRESSED BY RESOURCE ID, NEVER BY PATH. ─────────────────┐
    | │ The client sends a content-item UUID. The storage path is read out  │
    | │ of the `notes` row AFTER the gate has run, so there is no           │
    | │ client-controlled string anywhere near the filesystem on this       │
    | │ route — which is why it needs no traversal defence of its own.      │
    | │                                                                      │
    | │ The gate is ContentItemPolicy::downloadFile, which delegates to the │
    | │ SAME consumeContent ability that decides whether the note's TEXT is │
    | │ serialised at all (row 21). A file must never be easier to reach    │
    | │ than the note it is attached to.                                     │
    | │                                                                      │
    | │ **This route is what closed open decision 8.4.** The ability had a  │
    | │ LogicException body for four stages precisely because nothing       │
    | │ routed to it; a real body without a real route would still have     │
    | │ been dead code.                                                      │
    | └──────────────────────────────────────────────────────────────────────┘
    */
    Route::middleware(['auth', 'active'])->group(function (): void {
        Route::get('/content-items/{id}/file', [FileController::class, 'download'])
            ->name('content-items.file');
    });

    /*
    |--------------------------------------------------------------------------
    | Platform settings (Stage 10) — 2 routes
    |--------------------------------------------------------------------------
    |
    | FREEZE-TABLE ROWS 126 AND 127 — THE LAST TWO ROWS IN THE TABLE. With
    | row 56 above, this file now registers all 127 (Method, Path) pairs that
    | backend/routes/api.php registers, and nothing else.
    |
    | ╔══════════════════════════════════════════════════════════════════════╗
    | ║ THE READ IS ANONYMOUS ON PURPOSE — DO NOT ADD `auth` HERE.           ║
    | ║                                                                      ║
    | ║ Footer.tsx and WhatsAppWidget.tsx render on EVERY page of the        ║
    | ║ public storefront, for visitors who have no session and never will.  ║
    | ║ Gating this route does not tighten anything; it blanks the support   ║
    | ║ contacts and the social links for every logged-out visitor and       ║
    | ║ leaves a 401 in the browser console on every page load.              ║
    | ║                                                                      ║
    | ║ The store is allowed to be public because it can hold nothing but    ║
    | ║ public display data, and that is enforced on BOTH sides now: the     ║
    | ║ write allow-list (UpdateSettingsRequest, three nesting levels) and   ║
    | ║ the read allow-list (PublicSettingsMap). The read filter is a        ║
    | ║ user-approved change from backend/'s unfiltered SELECT — open        ║
    | ║ decision 10.3.                                                       ║
    | ╚══════════════════════════════════════════════════════════════════════╝
    |
    | ┌── THE WRITE CARRIES NO RATE LIMIT, MATCHING backend/. ───────────────┐
    | │ It is admin-only and idempotent, and the six existing buckets        │
    | │ (login, register, update-credentials, upload, submit, broadcast) all │
    | │ exist because their route is either unauthenticated or costs         │
    | │ O(recipients)/disk work per call. Neither applies here. Adding a     │
    | │ seventh bucket would be a new behaviour, not a port.                 │
    | └──────────────────────────────────────────────────────────────────────┘
    |
    | ┌── AND THE PART THAT IS NOT IN THIS FILE BUT BELONGS TO THIS ROUTE: ──┐
    | │ `POST api/v1/settings` IS ON AppServiceProvider::                    │
    | │ preserveEmptyStringsOnClearableWrites()'s allow-list, and it is the  │
    | │ FOURTH module to need it — with a worse failure mode than the first  │
    | │ three. Everywhere else the default middleware caused a silent        │
    | │ no-op; here it causes an ORDINARY SAVE TO ANSWER 422, because the    │
    | │ real payload always contains eleven social links whose `url` is ''   │
    | │ and five support contacts whose `value` is ''. Rewritten to null,    │
    | │ every one of them fails its `string` rule. See that method.          │
    | └──────────────────────────────────────────────────────────────────────┘
    */
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');

    // PlatformSettings ("إعدادات المنصة") — decision 10.40.
    Route::middleware([
        'auth', 'active', 'role:admin',
        'employee.permission:' . EmployeePermissions::ADMIN_PLATFORM_SETTINGS_MANAGE,
    ])->group(function (): void {
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });

    /*
    |--------------------------------------------------------------------------
    | Center System — 19 routes (freeze-table rows 121-139, decision 10.24)
    |--------------------------------------------------------------------------
    |
    | Reuses the existing Course/Exam/Student/Teacher models throughout — no
    | parallel course or exam system. Every teacher-scoped route below resolves
    | identity from `$request->user()` alone; every ownership check routes
    | through App\Support\CenterAccess (or its Policy for /admin/center-codes,
    | which is `role:admin` only, same shape as /admin/enrollment-codes).
    */
    Route::middleware(['auth', 'active', 'role:admin'])->prefix('admin')->name('admin.')->group(function (): void {
        // GenerateCenterCodes.tsx ("توليد أكواد السنتر") — decision 10.40.
        Route::post('/center-codes', [CenterCodeController::class, 'store'])
            ->middleware('employee.permission:' . EmployeePermissions::ADMIN_CENTER_CODES_MANAGE)
            ->name('center-codes.store');

        // Not currently called by the frontend, but pairs with the store route above
        // and has no other ADMIN_NAV owner.
        Route::get('/center-codes', [CenterCodeController::class, 'index'])
            ->middleware('employee.permission:' . EmployeePermissions::ADMIN_CENTER_CODES_MANAGE)
            ->name('center-codes.index');

        // Literal segment BEFORE the '{code}' wildcard directly below — same
        // hazard as '/enrollment-codes/search' (row 114).
        // CenterCodeDetails.tsx ("تفاصيل كود سنتر") — decision 10.40.
        Route::get('/center-codes/search', [CenterCodeController::class, 'search'])
            ->middleware('employee.permission:' . EmployeePermissions::ADMIN_CENTER_CODE_DETAILS_VIEW)
            ->name('center-codes.search');

        Route::get('/center-codes/{code}', [CenterCodeController::class, 'show'])
            ->middleware('employee.permission:' . EmployeePermissions::ADMIN_CENTER_CODE_DETAILS_VIEW)
            ->name('center-codes.show');

        // Row 140, decision 10.25 — "فك الربط" on the code-details table.
        Route::delete('/center-memberships/{id}', [CenterCodeController::class, 'unlinkMembership'])
            ->middleware('employee.permission:' . EmployeePermissions::ADMIN_CENTER_CODE_DETAILS_VIEW)
            ->name('center-memberships.destroy');
    });

    // "زر تفعيل نظام السنتر" — every route below requires
    // teachers.center_enabled, checked AFTER role:instructor.
    Route::middleware(['auth', 'active', 'role:instructor', 'center.enabled'])->group(function (): void {
        Route::get('/center/groups', [CenterGroupController::class, 'index'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_GROUPS_MANAGE)
            ->name('center.groups.index');

        Route::post('/center/groups', [CenterGroupController::class, 'store'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_GROUPS_MANAGE)
            ->name('center.groups.store');

        Route::patch('/center/groups/{id}', [CenterGroupController::class, 'update'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_GROUPS_MANAGE)
            ->name('center.groups.update');

        Route::delete('/center/groups/{id}', [CenterGroupController::class, 'destroy'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_GROUPS_MANAGE)
            ->name('center.groups.destroy');

        Route::get('/center/groups/{id}/sessions', [CenterGroupController::class, 'sessions'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_ATTENDANCE_MANAGE)
            ->name('center.groups.sessions');

        Route::post('/center/sessions', [CenterSessionController::class, 'store'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_ATTENDANCE_MANAGE)
            ->name('center.sessions.store');

        Route::post('/center/sessions/{id}/exams', [CenterSessionController::class, 'attachExams'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_ATTENDANCE_MANAGE)
            ->name('center.sessions.exams');

        // Row 141-143, decision 10.26 — "اختر الكورس" (center-visible only)
        // then multi-select its own videos/exams for the active session —
        // both reached only from the attendance page.
        Route::get('/center/courses', [CenterCourseController::class, 'index'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_ATTENDANCE_MANAGE)
            ->name('center.courses.index');

        Route::get('/center/courses/{id}/materials', [CenterCourseController::class, 'materials'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_ATTENDANCE_MANAGE)
            ->name('center.courses.materials');

        // "تفعيل كورس سنتر" — free grant, no Subscription row, either bulk
        // (every attendee of one session) or one student by identifier. Its
        // OWN page/permission, distinct from the attendance page above even
        // though it also reads a course/session.
        Route::post('/center/courses/{id}/activate', [CenterCourseController::class, 'activate'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_COURSE_ACTIVATION_MANAGE)
            ->name('center.courses.activate');

        Route::post('/center/sessions/{id}/videos', [CenterSessionController::class, 'attachVideos'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_ATTENDANCE_MANAGE)
            ->name('center.sessions.videos');

        Route::get('/center/sessions/{id}', [CenterSessionController::class, 'show'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_ATTENDANCE_MANAGE)
            ->name('center.sessions.show');

        Route::post('/center/sessions/{id}/attendance', [CenterSessionController::class, 'markAttendance'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_ATTENDANCE_MANAGE)
            ->name('center.sessions.attendance.store');

        Route::delete(
            '/center/sessions/{id}/attendance/{attendanceId}',
            [CenterSessionController::class, 'deleteAttendance']
        )->middleware('employee.permission:' . EmployeePermissions::CENTER_ATTENDANCE_MANAGE)
            ->name('center.sessions.attendance.destroy');

        Route::patch('/center/attendance/{id}/note', [CenterSessionController::class, 'updateNote'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_ATTENDANCE_MANAGE)
            ->name('center.attendance.note');

        Route::post('/center/sessions/{id}/close', [CenterSessionController::class, 'close'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_ATTENDANCE_MANAGE)
            ->name('center.sessions.close');

        // Row 144-145, decision 10.27 — "استعادة حضور الحصة" / "حذف الحصة
        // بالكامل" from the session report.
        Route::post('/center/sessions/{id}/reopen', [CenterSessionController::class, 'reopen'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_ATTENDANCE_MANAGE)
            ->name('center.sessions.reopen');

        Route::delete('/center/sessions/{id}', [CenterSessionController::class, 'destroy'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_ATTENDANCE_MANAGE)
            ->name('center.sessions.destroy');

        Route::get('/center/reports/students', [CenterReportController::class, 'students'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_REPORTS_VIEW)
            ->name('center.reports.students');

        // "فك الربط" from the students report — a teacher-scoped twin of the
        // admin's own unlink endpoint.
        Route::delete('/center/memberships/{id}', [CenterReportController::class, 'unlinkStudent'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_REPORTS_VIEW)
            ->name('center.memberships.unlink');

        // "متابعة مدفوعات السنتر" — a PURE paid/unpaid tracking log, never a
        // financial record. See App\Models\CenterPaymentRecord's docblock.
        Route::get('/center/payments/students', [CenterPaymentController::class, 'students'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_PAYMENTS_MANAGE)
            ->name('center.payments.students');

        Route::post('/center/payments', [CenterPaymentController::class, 'store'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_PAYMENTS_MANAGE)
            ->name('center.payments.store');

        // MORE segments than '/center/payments/students' above, so the two
        // GETs cannot collide — same convention as every other Center route.
        Route::get('/center/payments/students/{studentId}/report', [CenterPaymentController::class, 'report'])
            ->middleware('employee.permission:' . EmployeePermissions::CENTER_PAYMENTS_MANAGE)
            ->name('center.payments.report');
    });

    Route::middleware(['auth', 'active', 'role:student'])->group(function (): void {
        Route::post('/center/join', [CenterStudentController::class, 'join'])
            ->name('center.join');

        Route::get('/center/my-centers', [CenterStudentController::class, 'myCenters'])
            ->name('center.my-centers');
    });

});
