<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Admin\ListLoginActivity;
use App\Enums\LoginSessionStatus;
use App\Http\Resources\LoginActivityResource;
use App\Models\LoginSession;
use App\Models\User;
use App\Support\UserAgentParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/admin/login-activity — freeze-table row 56, the last /admin row.
 */
final class LoginActivityTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /** The exact fourteen keys, in backend/AdminDashboardService's order. */
    private const KEYS = [
        'id',
        'userId',
        'userName',
        'userEmail',
        'userType',
        'ipAddress',
        'status',
        'loginDate',
        'lastActive',
        'lastActivity',
        'browser',
        'os',
        'deviceType',
        'device',
    ];

    private function makeSession(User $user, array $attributes = []): LoginSession
    {
        return LoginSession::query()->create(array_merge([
            'user_id' => $user->getKey(),
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
            'status' => LoginSessionStatus::Active,
            'created_at' => now(),
            'last_activity_at' => now(),
        ], $attributes));
    }

    public function test_the_payload_is_under_the_sessions_key_with_exactly_fourteen_fields(): void
    {
        $this->disableRateLimiting();
        $admin = $this->loginAsAdmin();
        $this->makeSession($admin);

        $rows = $this->getJson('/api/v1/admin/login-activity')->assertOk()->json('data.sessions');

        // The wrapper key is `sessions`, not `loginActivity` — the frontend
        // reads data.sessions. There is also the admin's OWN login row from
        // loginAsAdmin(), so at least two rows exist.
        self::assertGreaterThanOrEqual(2, \count($rows));
        self::assertSame(self::KEYS, array_keys($rows[0]), 'Fourteen keys, in backend/`s order.');
    }

    /**
     * `country` and `city` are the obvious "improvement" and are deliberately
     * absent — this system has no geo-IP lookup and inventing one would put
     * fabricated evidence in front of an administrator. Stated as a test so
     * adding them is a red build, not a review comment.
     */
    public function test_country_and_city_are_never_emitted(): void
    {
        $this->disableRateLimiting();
        $admin = $this->loginAsAdmin();
        $this->makeSession($admin);

        $rows = $this->getJson('/api/v1/admin/login-activity')->assertOk()->json('data.sessions');

        foreach ($rows as $row) {
            self::assertArrayNotHasKey('country', $row);
            self::assertArrayNotHasKey('city', $row);
        }
    }

    public function test_last_active_and_last_activity_are_the_same_value(): void
    {
        $this->disableRateLimiting();
        $admin = $this->loginAsAdmin();
        $this->makeSession($admin, ['last_activity_at' => now()->subMinutes(9)]);

        $rows = $this->getJson('/api/v1/admin/login-activity')->assertOk()->json('data.sessions');

        foreach ($rows as $row) {
            self::assertNotNull($row['lastActive']);
            self::assertSame($row['lastActive'], $row['lastActivity'], 'The duplication is the contract.');
        }
    }

    public function test_the_derived_columns_come_from_the_user_agent(): void
    {
        $this->disableRateLimiting();
        $admin = $this->loginAsAdmin();

        $session = $this->makeSession($admin, [
            'user_agent' => 'Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
        ]);

        $rows = collect($this->getJson('/api/v1/admin/login-activity')->assertOk()->json('data.sessions'));
        $row = $rows->firstWhere('id', $session->getKey());

        self::assertSame('Safari', $row['browser']);
        self::assertSame('iOS', $row['os']);
        self::assertSame(UserAgentParser::DEVICE_TABLET, $row['deviceType']);
        // Middle dot U+00B7, one space on each side.
        self::assertSame(UserAgentParser::DEVICE_TABLET . ' · iOS', $row['device']);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ REACHING THE EM-DASH BRANCH REQUIRED DISABLING A FOREIGN KEY, AND    ║
     * ║ THAT DISCOVERY IS THE POINT OF THIS TEST.                            ║
     * ║                                                                      ║
     * ║ The obvious fixture — a user with a null `full_name` and a null      ║
     * ║ `username` — CANNOT EXIST: both columns are NOT NULL in `users`, in  ║
     * ║ backend/ (migration 0001) and in v2 alike. And the row cannot be     ║
     * ║ orphaned either, because `fk_login_sessions_user` is ON DELETE       ║
     * ║ CASCADE.                                                             ║
     * ║                                                                      ║
     * ║ So backend/'s `full_name ?? username ?? '—'` chain is DEFENSIVE:     ║
     * ║ under the schema as written, the second and third rungs are          ║
     * ║ unreachable through any application path. It is preserved anyway     ║
     * ║ (the port's contract is the same expression, not the same expression ║
     * ║ minus the parts proved redundant), and it is exercised here by       ║
     * ║ inserting a genuinely orphaned row with FOREIGN_KEY_CHECKS off —     ║
     * ║ the state a legacy import or a restored partial backup can produce.  ║
     * ║                                                                      ║
     * ║ Recorded as open decision 10.5.                                      ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * The dash is U+2014 EM DASH, asserted against its codepoint so a
     * look-alike hyphen or en dash cannot pass.
     */
    public function test_an_orphaned_session_row_falls_back_to_an_em_dash(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $orphanId = (string) \Illuminate\Support\Str::uuid7();

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            DB::table('login_sessions')->insert([
                'id' => $orphanId,
                'user_id' => (string) \Illuminate\Support\Str::uuid7(), // no such user
                'ip_address' => '198.51.100.4',
                'user_agent' => 'curl/8.4.0',
                'status' => LoginSessionStatus::Active->value,
                'created_at' => now()->addDay()->toDateTimeString(),
                'last_activity_at' => now()->toDateTimeString(),
            ]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $rows = collect($this->getJson('/api/v1/admin/login-activity')->assertOk()->json('data.sessions'));
        $row = $rows->firstWhere('id', $orphanId);

        self::assertNotNull($row, 'An orphaned audit row must still be listed, not dropped.');
        self::assertSame(LoginActivityResource::NO_NAME, $row['userName']);
        self::assertSame("\u{2014}", $row['userName'], 'Must be an EM DASH, not - or –.');
        // The other three user-derived fields go null rather than blank.
        self::assertNull($row['userEmail']);
        self::assertNull($row['userType']);
    }

    /**
     * `??` IS NULL-COALESCING, NOT TRUTHINESS — AND THAT DIFFERENCE IS VISIBLE.
     *
     * An account whose `full_name` is the EMPTY STRING renders an EMPTY CELL,
     * NOT the username and NOT the dash: '' is not null, so the chain stops at
     * the first rung. Rewriting `??` as `?:` or as `!empty()` would silently
     * change this row's output, which is exactly the kind of "tidying" that
     * looks equivalent in review.
     */
    public function test_an_empty_full_name_does_not_fall_through_to_the_username(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $user = $this->makeUser(['full_name' => '']);
        $session = $this->makeSession($user);

        $rows = collect($this->getJson('/api/v1/admin/login-activity')->assertOk()->json('data.sessions'));

        self::assertSame('', $rows->firstWhere('id', $session->getKey())['userName']);
    }

    /**
     * backend/'s LEFT JOIN carries no `deleted_at IS NULL`, so a removed
     * account's name STILL appears in the audit log — which is correct for an
     * audit log. Laravel's SoftDeletes global scope would erase it silently,
     * so ListLoginActivity eager-loads withTrashed(). This is that pin.
     */
    public function test_a_soft_deleted_users_name_is_still_shown(): void
    {
        $this->disableRateLimiting();
        $this->loginAsAdmin();

        $victim = $this->makeUser(['full_name' => 'حساب محذوف']);
        $session = $this->makeSession($victim);
        $victim->delete();

        self::assertNotNull($victim->fresh()?->deleted_at ?? User::withTrashed()->find($victim->getKey())?->deleted_at);

        $rows = collect($this->getJson('/api/v1/admin/login-activity')->assertOk()->json('data.sessions'));
        $row = $rows->firstWhere('id', $session->getKey());

        self::assertNotNull($row, 'The session row itself must survive the soft delete.');
        self::assertSame('حساب محذوف', $row['userName'], 'withTrashed() was dropped from the eager load.');
    }

    /** Newest first, and capped at 300 without pagination. */
    public function test_rows_are_ordered_newest_first(): void
    {
        $this->disableRateLimiting();
        $admin = $this->loginAsAdmin();

        $old = $this->makeSession($admin, ['created_at' => now()->subDays(3)]);
        $new = $this->makeSession($admin, ['created_at' => now()->addDays(3)]);

        $ids = collect($this->getJson('/api/v1/admin/login-activity')->assertOk()->json('data.sessions'))
            ->pluck('id')
            ->all();

        self::assertLessThan(
            array_search($old->getKey(), $ids, true),
            array_search($new->getKey(), $ids, true),
            'ORDER BY created_at DESC'
        );
    }

    public function test_the_limit_is_three_hundred_and_no_query_parameter_can_widen_it(): void
    {
        self::assertSame(300, ListLoginActivity::LIMIT);

        $this->disableRateLimiting();
        $admin = $this->loginAsAdmin();

        // 301 rows plus the admin's own login row from loginAsAdmin().
        $now = now();
        $bulk = [];

        for ($i = 0; $i < 301; $i++) {
            $bulk[] = [
                'id' => (string) \Illuminate\Support\Str::uuid7(),
                'user_id' => $admin->getKey(),
                'ip_address' => '203.0.113.7',
                'user_agent' => 'curl/8.4.0',
                'status' => LoginSessionStatus::Active->value,
                'created_at' => $now->copy()->subSeconds($i)->toDateTimeString(),
                'last_activity_at' => $now->toDateTimeString(),
            ];
        }

        DB::table('login_sessions')->insert($bulk);

        $rows = $this->getJson('/api/v1/admin/login-activity?limit=100000')
            ->assertOk()
            ->json('data.sessions');

        self::assertCount(300, $rows, 'The cap is structural — no request input reaches the query.');
    }

    /** A CONSTANT number of queries regardless of row count: no N+1. */
    public function test_the_endpoint_does_not_issue_a_query_per_row(): void
    {
        $this->disableRateLimiting();
        $admin = $this->loginAsAdmin();

        for ($i = 0; $i < 25; $i++) {
            $this->makeSession($this->makeUser());
        }

        $this->startNextRequest();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->getJson('/api/v1/admin/login-activity')->assertOk();

        $count = \count(DB::getQueryLog());
        DB::disableQueryLog();

        // Session + auth + the two the Action itself needs. Far below 26.
        self::assertLessThan(10, $count, "N+1: {$count} queries for 26 rows.");
    }

    public function test_a_guest_gets_401_and_a_student_gets_403(): void
    {
        $this->disableRateLimiting();

        $this->getJson('/api/v1/admin/login-activity')->assertStatus(401);

        $this->startNextRequest();
        $this->loginAs($this->makeStudentUser());

        $this->getJson('/api/v1/admin/login-activity')->assertStatus(403);
    }

    public function test_an_instructor_cannot_read_the_platform_wide_history(): void
    {
        $this->disableRateLimiting();
        $this->loginAs($this->makeInstructor());

        $this->getJson('/api/v1/admin/login-activity')->assertStatus(403);
    }

    /**
     * ONLY TWO OF THE FIVE `LoginSessionStatus` VALUES ARE EVER WRITTEN.
     *
     * The enum has five ('Active', 'Logged Out', 'Session Expired', 'Blocked',
     * 'Suspicious Activity') because the column's ENUM does, but a grep of the
     * whole application finds writes of exactly two: LoginUser writes Active
     * and AuthController writes LoggedOut. The other three are DEAD VALUES —
     * schema surface with no producer.
     *
     * This test states that empirically rather than in a comment, so that a
     * later stage that starts writing 'Blocked' has to come here and say so.
     * Recorded as open decision 10.1; no code was added to write them.
     */
    public function test_only_two_of_the_five_status_values_are_ever_written(): void
    {
        $written = [];

        foreach ([app_path('Actions'), app_path('Http'), app_path('Models')] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                foreach (LoginSessionStatus::cases() as $case) {
                    if (str_contains($source, 'LoginSessionStatus::' . $case->name)) {
                        $written[$case->value] = true;
                    }
                }
            }
        }

        ksort($written);

        self::assertSame(
            ['Active', 'Logged Out'],
            array_keys($written),
            'A third status value gained a producer — update open decision 10.1.'
        );
    }
}
