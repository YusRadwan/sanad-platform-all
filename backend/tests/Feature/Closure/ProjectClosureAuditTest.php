<?php

declare(strict_types=1);

namespace Tests\Feature\Closure;

use App\Models\Notification;
use App\Models\Subscription;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ STAGE 10 — THE PERMANENT CLOSURE AUDIT.                                 ║
 * ║                                                                          ║
 * ║ Seven structural facts that are true RIGHT NOW across the whole          ║
 * ║ application, each of which was established by a different stage and any  ║
 * ║ of which could be undone by a plausible future commit WITHOUT breaking   ║
 * ║ a single feature test:                                                   ║
 * ║                                                                          ║
 * ║   1. zero `LogicException` placeholders in app/Policies/    (Stage 9)    ║
 * ║   2. public/storage does not exist                          (Stage 9)    ║
 * ║   3. no Storage::disk('public') anywhere in upload code     (Stage 9)    ║
 * ║   4. no Policy method can fall through to an accidental `true`           ║
 * ║   5. Subscription::scopeActive() is the ONLY definition of "active"      ║
 * ║   6. Notification::scopeVisible() is on BOTH read paths     (Stage 8)    ║
 * ║   7. no route exists outside the api/v1 prefix                           ║
 * ║                                                                          ║
 * ║ These are REFLECTIVE tests: they read the source tree and the router,    ║
 * ║ not the behaviour of one endpoint. That is the point — a behavioural     ║
 * ║ test proves one path is right today; these prove no path can quietly     ║
 * ║ become wrong tomorrow.                                                   ║
 * ║                                                                          ║
 * ║ EVERY ONE OF THEM CARRIES A NEGATIVE CONTROL in the same test, because   ║
 * ║ a source-scanning assertion that finds nothing is indistinguishable      ║
 * ║ from a scanner pointed at the wrong directory.                           ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class ProjectClosureAuditTest extends TestCase
{
    /** @return list<string> absolute paths of every .php file under $dir */
    private static function phpFilesIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Strips block comments, line comments and docblocks so a scan sees CODE.
     *
     * Without this, audit 1 below fails on ContentItemPolicy — whose docblock
     * says "THE LAST `LogicException` PLACEHOLDER IN THE ENTIRE APPLICATION"
     * as a historical note. A grep that cannot tell a comment from a statement
     * would report the very sentence celebrating the fix as a violation.
     */
    private static function codeOnly(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                if (\in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    // ── AUDIT 1 ──────────────────────────────────────────────────────────

    /**
     * Stage 9 closed the last placeholder. Re-confirmed here so the result is
     * asserted by a test that OUTLIVES Stage 9's own suite.
     */
    public function test_audit_1_zero_logic_exception_placeholders_in_policies(): void
    {
        $offenders = [];

        foreach (self::phpFilesIn(app_path('Policies')) as $file) {
            $code = self::codeOnly((string) file_get_contents($file));

            if (str_contains($code, 'LogicException')) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, 'A Policy ability regained a placeholder body.');

        // NEGATIVE CONTROL: the scanner must actually be able to SEE the word.
        // If codeOnly() or phpFilesIn() were broken, the assertion above would
        // pass vacuously. This proves both work on a known-positive input.
        $probe = '<?php class X { public function y() { throw new \LogicException("x"); } }';
        self::assertStringContainsString('LogicException', self::codeOnly($probe));

        // …and that the directory really was non-empty.
        self::assertGreaterThanOrEqual(8, \count(self::phpFilesIn(app_path('Policies'))));

        // …and that a DOCBLOCK mention is correctly ignored (this is the exact
        // false positive a naive grep produces on ContentItemPolicy).
        $comment = '<?php /** LogicException was removed here */ class Y {}';
        self::assertStringNotContainsString('LogicException', self::codeOnly($comment));
    }

    // ── AUDIT 2 ──────────────────────────────────────────────────────────

    /**
     * `php artisan storage:link` must NEVER be run on this application. The
     * upload directory lives outside the document root and the ONLY readers
     * are the two file-serving routes, which is the whole security property.
     */
    public function test_audit_2_public_storage_symlink_does_not_exist(): void
    {
        $link = base_path('public/storage');

        self::assertFileDoesNotExist($link, 'storage:link was run — every upload is now web-readable.');
        self::assertFalse(is_link($link));

        // NEGATIVE CONTROL: public/ itself must exist, or the check above is
        // trivially true for the wrong reason.
        self::assertDirectoryExists(base_path('public'));
        self::assertFileExists(base_path('public/index.php'));
    }

    // ── AUDIT 3 ──────────────────────────────────────────────────────────

    public function test_audit_3_no_public_disk_reference_anywhere_in_app(): void
    {
        $offenders = [];

        foreach (self::phpFilesIn(app_path()) as $file) {
            $code = self::codeOnly((string) file_get_contents($file));

            if (preg_match('/disk\(\s*[\'"]public[\'"]\s*\)/', $code) === 1) {
                $offenders[] = str_replace(base_path() . \DIRECTORY_SEPARATOR, '', $file);
            }
        }

        self::assertSame([], $offenders, "Upload code reached for the web-readable 'public' disk.");

        // NEGATIVE CONTROL for the pattern itself.
        self::assertSame(1, preg_match('/disk\(\s*[\'"]public[\'"]\s*\)/', 'Storage::disk("public")->put($x);'));
        self::assertSame(0, preg_match('/disk\(\s*[\'"]public[\'"]\s*\)/', "Storage::disk('uploads')->put(\$x);"));
    }

    // ── AUDIT 4 ──────────────────────────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ NO POLICY METHOD MAY END BY GRANTING. FAIL CLOSED, ALWAYS.           ║
     * ║                                                                      ║
     * ║ A Policy that ends in `return true` is not necessarily wrong today —  ║
     * ║ but it is wrong the moment someone adds a case above it and forgets   ║
     * ║ the guard, because the fallthrough SILENTLY GRANTS instead of         ║
     * ║ silently denying. Every ability in this application is written so     ║
     * ║ the LAST statement denies, and this test is what keeps that true.     ║
     * ║                                                                      ║
     * ║ Abilities return `bool` or `Illuminate\Auth\Access\Response`, so the  ║
     * ║ accepted terminal forms are `return false`, `Response::deny…`,        ║
     * ║ `$this->deny…`, or a delegation to another guarded helper.            ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    /**
     * The ONLY abilities allowed to end in an unconditional `return true;`.
     *
     * One entry, and it is a freeze-table DEGRADE row: `viewAny` backs the
     * public course catalogue, which a logged-out visitor loads on every
     * storefront page. Filtering there is the QUERY SCOPE's job
     * (`Course::scopePublished()`), not the ability's — see the method's own
     * docblock and freeze-table row 8.
     */
    private const MAY_END_IN_TRUE = [
        'App\Policies\CoursePolicy::viewAny',
    ];

    public function test_audit_4_no_policy_ability_ends_by_granting(): void
    {
        $offenders = [];
        $abilitiesChecked = 0;
        $exemptionsSeen = [];

        foreach (self::phpFilesIn(app_path('Policies')) as $file) {
            if (str_contains($file, \DIRECTORY_SEPARATOR . 'Concerns' . \DIRECTORY_SEPARATOR)) {
                continue;
            }

            $class = 'App\\Policies\\' . basename($file, '.php');

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || $method->isConstructor()) {
                    continue;
                }

                $source = self::methodSource($method);

                if ($source === null) {
                    continue;
                }

                $abilitiesChecked++;

                $statements = preg_split('/\R/', trim(self::codeOnly($source))) ?: [];
                $statements = array_values(array_filter(
                    array_map('trim', $statements),
                    static fn ($l) => $l !== '' && $l !== '}'
                ));

                $last = (string) end($statements);
                $name = "{$class}::{$method->getName()}";

                /*
                 * THE PROPERTY IS "NO UNCONDITIONAL GRANT AT THE END", not
                 * "the last line literally says deny".
                 *
                 * An ability that ends in `return $group->teacher_id === $user
                 * ->id;` IS fail-closed — a non-owner gets false. Demanding a
                 * literal `return false` there would force every ownership
                 * check to be rewritten as an if/return-false pair, which is
                 * more code saying the same thing. The dangerous shape is the
                 * one that grants NO MATTER WHAT the branches above decided:
                 * a bare `return true;` as the final statement.
                 */
                if (preg_match('/^return\s+true\s*;$/', $last) !== 1) {
                    continue;
                }

                if (\in_array($name, self::MAY_END_IN_TRUE, true)) {
                    $exemptionsSeen[] = $name;

                    continue;
                }

                $offenders[] = "{$name}() ends with an unconditional grant: {$last}";
            }
        }

        self::assertSame([], $offenders, 'A Policy ability can fall through to an unconditional grant.');

        // NEGATIVE CONTROL 1: the walker must have actually found abilities.
        self::assertGreaterThan(20, $abilitiesChecked, 'The reflection walk found almost nothing — it is broken.');

        /*
         * NEGATIVE CONTROL 2, AND IT IS THE ONE THAT MATTERS.
         *
         * If the `return true` detector were broken, $offenders would be empty
         * for the wrong reason and this audit would be decorative. The
         * exemption list is therefore asserted to have been *exercised*: the
         * one legitimate unconditional grant in the application MUST have been
         * found and matched. A regex that stopped matching would fail here.
         */
        self::assertSame(
            self::MAY_END_IN_TRUE,
            $exemptionsSeen,
            'The known unconditional grant was not detected — the scanner is broken, not the code.'
        );

        // NEGATIVE CONTROL 3: the pattern itself.
        self::assertSame(1, preg_match('/^return\s+true\s*;$/', 'return true;'));
        self::assertSame(0, preg_match('/^return\s+true\s*;$/', 'return true && $x;'));
        self::assertSame(0, preg_match('/^return\s+true\s*;$/', 'return $a === $b;'));
    }

    /** Extracts a method's body text from its own file, by line range. */
    private static function methodSource(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        if ($file === false || $start === false || $end === false) {
            return null;
        }

        $lines = file($file);

        if ($lines === false) {
            return null;
        }

        return '<?php ' . implode('', \array_slice($lines, $start - 1, $end - $start + 1));
    }

    // ── AUDIT 5 ──────────────────────────────────────────────────────────

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ `Subscription::scopeActive()` IS THE SINGLE DEFINITION OF "ACTIVE".  ║
     * ║                                                                      ║
     * ║ backend/ repeated the two-part condition inline in many places —      ║
     * ║ three times in TeacherStatistics alone — and open decision 7.2        ║
     * ║ records that the product uses FOUR different words for "active".      ║
     * ║ The rewrite collapsed the entitlement meaning into one scope:         ║
     * ║                                                                      ║
     * ║     status = 'active' AND (expires_at IS NULL OR expires_at > now())  ║
     * ║                                                                      ║
     * ║ The second half is the one that gets forgotten. An inline copy that   ║
     * ║ checks only `status` grants access to an EXPIRED subscription until   ║
     * ║ some background job rewrites the column — i.e. a paid-content leak    ║
     * ║ that no test of the scope itself would ever see.                      ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    /**
     * The three places allowed to test `status = active` WITHOUT the expiry half.
     *
     * All three are REPORTING COUNTERS (or a referential-integrity GUARD in
     * StudentController's case) over the stored column, not entitlement
     * gates — nobody gains access to any content because of them. The first
     * two are carried over from backend/ deliberately and open decision 7.5
     * records the CoursesOverview one by name ("«مشترك» في نظرة الكورسات
     * ليست scopeActive() — تباين منقول عمدًا"). The third is new (open
     * decision 10.19): StudentController::destroy() refuses to delete a
     * student who has an Active-status subscription, using the same raw
     * stored-status reading as DashboardStats — deliberately, so the guard
     * agrees with what the admin UI's own "نشط/موقوف" badge already shows,
     * rather than with a second, expiry-aware definition the admin cannot see.
     *
     * The distinction this list encodes: counting/guarding on rows whose
     * column says 'active' is a REPORT or a GUARD; deciding whether a student
     * may watch a video is an ENTITLEMENT, and only Subscription::scopeActive()
     * may answer that.
     */
    private const STATUS_ONLY_COUNTERS = [
        'app/Actions/Admin/DashboardStats.php',
        'app/Actions/Enrollment/CoursesOverview.php',
        'app/Http/Controllers/StudentController.php',
    ];

    public function test_audit_5_no_inline_reimplementation_of_active_subscription(): void
    {
        /*
         * PART ONE — the expiry half, which is the half that gets forgotten.
         *
         * `expires_at` may appear as a SELECTED COLUMN or a serialised field
         * anywhere (SubscriptionResource, the admin listing and the student
         * dashboard all display it, and that is not a rule). What may not
         * appear outside the model is `expires_at` inside a WHERE-family call
         * — that is a second entitlement definition.
         */
        $conditionPattern = '/where[A-Za-z]*\(\s*[^)]*expires_at/';
        $offenders = [];

        foreach (self::phpFilesIn(app_path()) as $file) {
            $relative = str_replace(\DIRECTORY_SEPARATOR, '/', str_replace(base_path() . \DIRECTORY_SEPARATOR, '', $file));

            if ($relative === 'app/Models/Subscription.php') {
                continue; // the definition itself
            }

            // EnrollmentCode has its OWN, unrelated expires_at column.
            if (str_contains($relative, 'EnrollmentCode')) {
                continue;
            }

            $code = self::codeOnly((string) file_get_contents($file));

            if (preg_match($conditionPattern, $code) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'A second entitlement definition using expires_at appeared.');

        /*
         * PART TWO — the status half. A `status = active` check outside the
         * scope is only acceptable for the two documented counters.
         */
        $statusOffenders = [];

        foreach (self::phpFilesIn(app_path()) as $file) {
            $relative = str_replace(\DIRECTORY_SEPARATOR, '/', str_replace(base_path() . \DIRECTORY_SEPARATOR, '', $file));

            if ($relative === 'app/Models/Subscription.php' || \in_array($relative, self::STATUS_ONLY_COUNTERS, true)) {
                continue;
            }

            $code = self::codeOnly((string) file_get_contents($file));

            if (preg_match('/where[A-Za-z]*\(\s*[^)]*SubscriptionStatus::Active/', $code) === 1) {
                $statusOffenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $statusOffenders,
            'A new status-only subscription check appeared outside the two documented counters.'
        );

        // NEGATIVE CONTROL: both counters must STILL match the pattern, or the
        // exemption list is stale and the scan is proving nothing.
        foreach (self::STATUS_ONLY_COUNTERS as $relative) {
            $code = self::codeOnly((string) file_get_contents(base_path($relative)));

            self::assertSame(
                1,
                preg_match('/where[A-Za-z]*\(\s*[^)]*SubscriptionStatus::Active/', $code),
                "{$relative} no longer contains the documented status-only check — update the list."
            );
        }

        // NEGATIVE CONTROL: the condition pattern distinguishes a WHERE from a
        // display field, which is the entire basis of Part One.
        self::assertSame(1, preg_match($conditionPattern, "\$q->whereNull('expires_at')"));
        self::assertSame(1, preg_match($conditionPattern, "\$q->where('expires_at', '>', now())"));
        self::assertSame(0, preg_match($conditionPattern, "'expires_at' => \$row->expires_at,"));
        self::assertSame(0, preg_match($conditionPattern, "->select('s.expires_at')"));

        // NEGATIVE CONTROL: the scope really does carry BOTH halves.
        $definition = self::codeOnly((string) file_get_contents(app_path('Models/Subscription.php')));

        self::assertStringContainsString('function scopeActive', $definition);
        self::assertStringContainsString('expires_at', $definition);
        self::assertStringContainsString('SubscriptionStatus::Active', $definition);

        // …and that it is genuinely reachable as a query scope.
        self::assertStringContainsString(
            'expires_at',
            Subscription::query()->active()->toSql(),
            'scopeActive() stopped constraining the expiry.'
        );
    }

    // ── AUDIT 6 ──────────────────────────────────────────────────────────

    /**
     * Stage 8's finding: `visible()` gates broadcast-sourced rows by the
     * parent's is_active flag and start_at/end_at window. It must be on BOTH
     * read paths — listing AND mark-read — or a recipient can mark-read (and
     * thereby confirm the existence of) a notification that the list correctly
     * hides. backend/ applied it on neither.
     */
    public function test_audit_6_notification_visible_scope_is_on_both_read_paths(): void
    {
        $controller = self::codeOnly(
            (string) file_get_contents(app_path('Http/Controllers/NotificationController.php'))
        );

        self::assertSame(
            2,
            substr_count($controller, '->visible()'),
            'visible() must appear on BOTH index() and markRead() — no more, no fewer.'
        );

        // Bind each occurrence to its method, not just to the file.
        foreach (['index', 'markRead'] as $name) {
            $method = new ReflectionMethod(\App\Http\Controllers\NotificationController::class, $name);
            $body = (string) self::methodSource($method);

            self::assertStringContainsString(
                '->visible()',
                self::codeOnly($body),
                "NotificationController::{$name}() lost the visible() scope."
            );
        }

        // NEGATIVE CONTROL: the scope exists and actually constrains.
        self::assertStringContainsString('is_active', Notification::query()->visible()->toSql());
    }

    // ── AUDIT 7 ──────────────────────────────────────────────────────────

    /**
     * Every registered route lives under api/v1. No Laravel default probe
     * (`/up`), no `/sanctum/csrf-cookie`, no leaked `_verify` test route.
     *
     * routes/testing.php is registered ONLY when APP_ENV=testing, so under the
     * test suite `_verify` routes DO exist and are expected — they are
     * asserted to be inside api/v1 like everything else, and their absence
     * from other environments is Stage 3's VerificationRouteIsolationTest.
     */
    public function test_audit_7_no_route_exists_outside_the_api_v1_prefix(): void
    {
        $offenders = [];
        $total = 0;

        foreach (Route::getRoutes() as $route) {
            $total++;
            $uri = '/' . ltrim($route->uri(), '/');

            if (!preg_match('#^/api/v1(/|$)#', $uri)) {
                $offenders[] = implode('|', $route->methods()) . ' ' . $uri;
            }
        }

        self::assertSame([], $offenders, 'A route escaped the api/v1 prefix.');

        // NEGATIVE CONTROL: the router was actually populated.
        self::assertGreaterThan(100, $total, 'Route collection is empty — the audit proved nothing.');

        // And the specific defaults that would be easiest to enable by accident.
        $uris = array_map(static fn ($r) => '/' . ltrim($r->uri(), '/'), iterator_to_array(Route::getRoutes()));

        foreach (['/up', '/sanctum/csrf-cookie', '/storage/{path}'] as $forbidden) {
            self::assertNotContains($forbidden, $uris, "Laravel default route {$forbidden} is registered.");
        }
    }

    // ── The freeze-table invariant itself ────────────────────────────────

    /**
     * THE PROJECT'S GOVERNING NUMBER, ASSERTED AS A TEST.
     *
     * Open decision 10.0 fixed the authoritative total at 122, decision 10.10
     * dropped it to 112 by deleting the "المناهج والدول" (curricula /
     * countries) section wholesale, decision 10.11 raised it to 113 by
     * adding PATCH /catalogs/{type}/reorder (manual grade-catalog ordering,
     * part of unifying "الصف الدراسي" between student signup and teacher
     * classification into one admin-managed catalog), decision 10.13 raised
     * it to 114 by adding GET /admin/enrollment-codes/search (the admin
     * enrollment-code filter/review screen), decision 10.18 raised it to
     * 116 by adding POST /admin/students/{id}/impersonate and POST
     * /auth/stop-impersonation (admin "view as student"), decision 10.20
     * raised it to 118 by adding the student's own watched-videos/
     * exam-attempts routes, and decision 10.23 raised it to 120 by adding
     * POST /teacher-applications (public "طلب مدرس جديد" submission) and
     * GET /admin/teacher-applications (its admin review list). Both sides
     * of the diff are recomputed here from the live router and from the
     * freeze table's own row count, so the closure claim cannot drift from
     * the documents that state it.
     *
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ THE INVARIANT IS "ONE ROW PER ROUTE", NOT "THE NUMBER NEVER MOVES".  │
     * │                                                                      │
     * │ 121 -> 122 on adding row 110, PATCH /catalogs/{type}/{id} (the safe   │
     * │ catalog rename). 122 -> 112 on deleting the 10 curricula routes.      │
     * │ 112 -> 113 on adding PATCH /catalogs/{type}/reorder. 113 -> 114 on    │
     * │ adding GET /admin/enrollment-codes/search. 114 -> 116 on adding the   │
     * │ two impersonation routes. 116 -> 118 on adding the student's own      │
     * │ watched-videos/exam-attempts routes. 118 -> 120 on adding the two     │
     * │ teacher-application routes. Bumping OR dropping this literal is the   │
     * │ CORRECT response to a deliberate route change — it is what forces     │
     * │ the freeze table to be updated in the same change, which is the       │
     * │ whole point of the test.                                              │
     * │                                                                      │
     * │ It is NOT licence to bump/drop it to silence a failure. If this       │
     * │ assertion fails and you did not knowingly add or remove a route, you  │
     * │ have LEAKED one — find it (`php artisan route:list`) rather than      │
     * │ changing the number.                                                  │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_freeze_table_has_exactly_one_row_per_registered_route(): void
    {
        $table = (string) file_get_contents(base_path('docs/authorization-freeze-table.md'));

        preg_match_all('/^\| *([0-9]+) \|/m', $table, $matches);

        $rowNumbers = array_map('intval', $matches[1]);

        self::assertCount(156, $rowNumbers, 'The freeze table must hold exactly 156 numbered rows.');
        self::assertSame(range(1, 156), $rowNumbers, 'Row numbers must be 1..156 with no gap or duplicate.');

        // The live router, counted by the SAME rule (one row per verb).
        $pairs = [];

        foreach (Route::getRoutes() as $route) {
            $uri = '/' . ltrim($route->uri(), '/');

            if (str_contains($uri, '_verify')) {
                continue; // testing-only, registered by bootstrap/app.php's then()
            }

            foreach ($route->methods() as $verb) {
                if (\in_array($verb, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $pairs[$verb . ' ' . $uri] = true;
            }
        }

        self::assertCount(
            156,
            $pairs,
            'The router must register exactly 156 (Method, Path) pairs — see open decisions 10.0, 10.10, 10.11, 10.13, 10.18, 10.20, 10.23, 10.24, 10.25, 10.26, 10.27, 10.29, 10.30, 10.32, 10.33, 10.36, 10.37 and 10.40 (10.38 was retracted and superseded by 10.40, which deleted its four routes).'
        );
    }

    /**
     * Every numbered open decision carries EXACTLY ONE terminal status tag.
     *
     * This is the documentation-completeness half of the closure audit: an
     * entry with no status is an undecided item pretending to be a record, and
     * an entry with two is a contradiction.
     */
    public function test_every_open_decision_entry_carries_exactly_one_status_tag(): void
    {
        $doc = (string) file_get_contents(base_path('docs/open-decisions.md'));

        preg_match_all('/^### ([0-9]+\.[0-9]+) — (.+)$/mu', $doc, $headings, PREG_SET_ORDER);

        self::assertGreaterThan(80, \count($headings), 'The entry scan found almost nothing.');

        preg_match_all(
            '/^\| *`?([0-9]+\.[0-9]+)`? *\| *\*\*(RESOLVED|DEFERRED|ACCEPTED-AS-IS)\*\* *\|/mu',
            $doc,
            $index,
            PREG_SET_ORDER
        );

        $tagged = [];

        foreach ($index as $row) {
            self::assertArrayNotHasKey($row[1], $tagged, "Entry {$row[1]} is listed twice in the status index.");
            $tagged[$row[1]] = $row[2];
        }

        $missing = [];

        foreach ($headings as $h) {
            if (!isset($tagged[$h[1]])) {
                $missing[] = $h[1];
            }
        }

        self::assertSame([], $missing, 'These open decisions have no terminal status in the index.');

        $orphans = array_diff(array_keys($tagged), array_column($headings, 1));

        self::assertSame([], array_values($orphans), 'The status index names entries that do not exist.');
    }
}
