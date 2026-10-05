<?php

declare(strict_types=1);

namespace Tests\Feature\Course;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE COURSE FIELD-SEMANTICS MATRIX. SAME RISK CLASS AS STAGE 4'S TEACHER ║
 * ║ TABLE, WITH ONE FIELD THAT INVERTS THE CONVENTION.                      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 *  field           absent      ''                        value
 *  ──────────────  ─────────  ────────────────────────  ──────────────────
 *  title           untouched  **422** (min:3)           update
 *  thumbnail_url   untouched  **CLEAR**                 update
 *  description     untouched  **CLEAR**                 update
 *  discount_price  untouched  **IGNORED**, NOT cleared  update
 *  price           untouched  **IGNORED**, NOT cleared  update
 *  is_published    untouched  n/a — PRESENCE-based      update, false included
 *
 * ┌── THE ONE ROW THIS FILE EXISTS FOR ──────────────────────────────────────┐
 * │ `description: ''` CLEARS the column and `discount_price: ''` DOES NOT —  │
 * │ IN THE SAME REQUEST BODY. Two adjacent keys, two opposite readings of    │
 * │ the identical JSON value.                                                │
 * │                                                                          │
 * │ test_the_inverted_convention_holds_for_both_fields_in_one_request sends  │
 * │ exactly that body and asserts both outcomes at once, because asserting   │
 * │ them in two separate tests would pass just as happily against an         │
 * │ implementation that had ONE rule and got lucky about which.              │
 * │                                                                          │
 * │ The reason for the asymmetry is the COLUMN TYPE, not a preference:       │
 * │ `description` is TEXT (so '' is storable and backend/'s array_filter     │
 * │ genuinely wrote it) while `discount_price` is DECIMAL(10,2), where a     │
 * │ literal '' is SQLSTATE 1366 under STRICT_TRANS_TABLES — measured against │
 * │ this project's own MySQL, not inferred. See                              │
 * │ App\Http\Requests\Course\CourseWriteRequest for the full probe.          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * EVERY ASSERTION HERE IS AGAINST THE DATABASE, not against the response
 * body. A presenter that echoed back what it was sent would satisfy a
 * response-shape assertion while writing nothing at all.
 */
final class CourseFieldSemanticsTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    private User $instructor;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->instructor] = $this->makeTeacherWithProfile();

        // Every column that the matrix can clear starts NON-EMPTY, so "the
        // value is gone" is a real observation rather than a value that was
        // never there.
        $this->course = $this->makeCourseFor($this->instructor, [
            'title' => 'العنوان الأصلي',
            'description' => 'وصف أصلي',
            'thumbnail_url' => 'https://cdn.example/original.png',
            'price' => 500,
            'discount_price' => 400,
            'is_published' => true,
        ]);

        $this->loginAs($this->instructor);
    }

    /** @return array<string,mixed> the row as MySQL now holds it */
    private function stored(): array
    {
        return (array) Course::query()->whereKey($this->course->getKey())->firstOrFail()->getAttributes();
    }

    private function save(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->putJson("/api/v1/courses/{$this->course->getKey()}", $body);
    }

    // ── title ─────────────────────────────────────────────────────────────

    public function test_an_absent_title_is_untouched(): void
    {
        $this->save(['description' => 'شيء آخر'])->assertStatus(200);

        $this->assertSame('العنوان الأصلي', $this->stored()['title']);
    }

    /**
     * '' IS A 422, NOT A CLEAR — the opposite of the two text fields beside
     * it. backend/ re-validates `required|string|min:3` whenever the key is
     * present, precisely so '' cannot be written over a valid title into a NOT
     * NULL column.
     */
    public function test_an_empty_title_is_rejected_with_422_and_writes_nothing(): void
    {
        $this->save(['title' => ''])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('العنوان الأصلي', $this->stored()['title']);
    }

    /** Two characters is still under min:3. */
    public function test_a_too_short_title_is_rejected(): void
    {
        $this->save(['title' => 'أب'])->assertStatus(422);

        $this->assertSame('العنوان الأصلي', $this->stored()['title']);
    }

    public function test_a_real_title_updates(): void
    {
        $this->save(['title' => 'عنوان جديد تمامًا'])->assertStatus(200);

        $this->assertSame('عنوان جديد تمامًا', $this->stored()['title']);
    }

    /**
     * A literal null must NOT blank a NOT NULL column. backend/'s array_filter
     * dropped nulls; here the absence of `nullable` on the rule turns it into
     * a 422 instead — either way the column survives, which is the property
     * that matters.
     */
    public function test_a_null_title_never_reaches_the_column(): void
    {
        $this->save(['title' => null])->assertStatus(422);

        $this->assertSame('العنوان الأصلي', $this->stored()['title']);
    }

    // ── description ───────────────────────────────────────────────────────

    public function test_an_absent_description_is_untouched(): void
    {
        $this->save(['title' => 'عنوان جديد تمامًا'])->assertStatus(200);

        $this->assertSame('وصف أصلي', $this->stored()['description']);
    }

    /** '' CLEARS. This is the "the instructor emptied the field" instruction. */
    public function test_an_empty_description_clears_the_column(): void
    {
        $this->save(['description' => ''])->assertStatus(200);

        $this->assertSame('', $this->stored()['description']);
    }

    /** null means "absent" — backend/'s array_filter dropped it. */
    public function test_a_null_description_leaves_the_column_alone(): void
    {
        $this->save(['description' => null])->assertStatus(200);

        $this->assertSame('وصف أصلي', $this->stored()['description']);
    }

    public function test_a_real_description_updates(): void
    {
        $this->save(['description' => 'وصف محدَّث'])->assertStatus(200);

        $this->assertSame('وصف محدَّث', $this->stored()['description']);
    }

    // ── thumbnail_url ─────────────────────────────────────────────────────

    public function test_an_absent_thumbnail_is_untouched(): void
    {
        $this->save(['title' => 'عنوان جديد تمامًا'])->assertStatus(200);

        $this->assertSame('https://cdn.example/original.png', $this->stored()['thumbnail_url']);
    }

    /** The "remove image" button. THE bug Stage 4's skipWhen exists to prevent. */
    public function test_an_empty_thumbnail_clears_the_column(): void
    {
        $this->save(['thumbnail_url' => ''])->assertStatus(200);

        $this->assertSame('', $this->stored()['thumbnail_url']);
    }

    public function test_a_null_thumbnail_leaves_the_column_alone(): void
    {
        $this->save(['thumbnail_url' => null])->assertStatus(200);

        $this->assertSame('https://cdn.example/original.png', $this->stored()['thumbnail_url']);
    }

    public function test_a_real_thumbnail_updates(): void
    {
        $this->save(['thumbnail_url' => 'https://cdn.example/new.png'])->assertStatus(200);

        $this->assertSame('https://cdn.example/new.png', $this->stored()['thumbnail_url']);
    }

    // ── discount_price — THE INVERTED ROW ─────────────────────────────────

    public function test_an_absent_discount_price_is_untouched(): void
    {
        $this->save(['title' => 'عنوان جديد تمامًا'])->assertStatus(200);

        $this->assertSame('400.00', $this->stored()['discount_price']);
    }

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE HIGHEST-RISK ASSERTION IN THIS SESSION.                          ║
     * ║                                                                      ║
     * ║ '' DOES NOT CLEAR AND DOES NOT ZERO THE DISCOUNT. It is read as "the ║
     * ║ key was not sent", and the stored 400.00 survives untouched.         ║
     * ║                                                                      ║
     * ║ The two failure modes this pins out:                                 ║
     * ║   * a "symmetric" implementation writes '' → MySQL 1366 → **500**    ║
     * ║   * a "helpful" implementation writes NULL or 0.00, and 0.00 tells   ║
     * ║     the storefront the course is 100% discounted                     ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_an_empty_discount_price_is_ignored_not_cleared(): void
    {
        $this->save(['discount_price' => ''])->assertStatus(200);

        $this->assertSame('400.00', $this->stored()['discount_price']);
    }

    public function test_a_null_discount_price_is_also_ignored(): void
    {
        $this->save(['discount_price' => null])->assertStatus(200);

        $this->assertSame('400.00', $this->stored()['discount_price']);
    }

    public function test_a_real_discount_price_updates(): void
    {
        $this->save(['discount_price' => 250])->assertStatus(200);

        $this->assertSame('250.00', $this->stored()['discount_price']);
    }

    /**
     * ┌── THE ONE-REQUEST PROOF ─────────────────────────────────────────────┐
     * │ One body, two identical '' values, two OPPOSITE outcomes. An         │
     * │ implementation with a single uniform rule for '' cannot pass this,   │
     * │ whichever rule it picked.                                            │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_the_inverted_convention_holds_for_both_fields_in_one_request(): void
    {
        $this->save([
            'description' => '',
            'thumbnail_url' => '',
            'discount_price' => '',
            'price' => '',
        ])->assertStatus(200);

        $stored = $this->stored();

        // CLEARED — text columns
        $this->assertSame('', $stored['description']);
        $this->assertSame('', $stored['thumbnail_url']);

        // UNTOUCHED — money columns
        $this->assertSame('400.00', $stored['discount_price']);
        $this->assertSame('500.00', $stored['price']);
    }

    // ── price ─────────────────────────────────────────────────────────────

    public function test_an_absent_price_is_untouched(): void
    {
        $this->save(['title' => 'عنوان جديد تمامًا'])->assertStatus(200);

        $this->assertSame('500.00', $this->stored()['price']);
    }

    /** Same inverted reading as discount_price, same DECIMAL reason. */
    public function test_an_empty_price_is_ignored_not_zeroed(): void
    {
        $this->save(['price' => ''])->assertStatus(200);

        $this->assertSame('500.00', $this->stored()['price']);
    }

    public function test_a_real_price_updates(): void
    {
        // 600 keeps the stored 400.00 discount valid; the pricing rule has its
        // own test file and must not be tripped incidentally here.
        $this->save(['price' => 600])->assertStatus(200);

        $this->assertSame('600.00', $this->stored()['price']);
    }

    public function test_a_negative_price_is_rejected(): void
    {
        $this->save(['price' => -10])->assertStatus(422);

        $this->assertSame('500.00', $this->stored()['price']);
    }

    // ── is_published — PRESENCE, never truthiness ─────────────────────────

    public function test_an_absent_is_published_is_untouched(): void
    {
        $this->save(['title' => 'عنوان جديد تمامًا'])->assertStatus(200);

        $this->assertSame(1, (int) $this->stored()['is_published']);
    }

    /**
     * ┌──────────────────────────────────────────────────────────────────────┐
     * │ `false` IS AN INSTRUCTION, NOT AN ABSENCE. This is the assertion     │
     * │ that fails the moment someone writes                                  │
     * │   if ($request->boolean('is_published')) { … }                        │
     * │ — a truthiness test cannot unpublish anything.                        │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public function test_is_published_false_actually_unpublishes(): void
    {
        $this->save(['is_published' => false])->assertStatus(200);

        $this->assertSame(0, (int) $this->stored()['is_published']);
    }

    public function test_is_published_true_publishes_a_draft(): void
    {
        $draft = $this->makeCourseFor($this->instructor, ['is_published' => false]);

        $this->putJson("/api/v1/courses/{$draft->getKey()}", ['is_published' => true])
            ->assertStatus(200);

        $this->assertSame(1, (int) $draft->refresh()->getAttributes()['is_published']);
    }

    /**
     * A body mentioning ONLY another field must not drag is_published down
     * with it — the exact silent-unpublish bug `has()`-before-`boolean()`
     * exists to prevent.
     */
    public function test_editing_the_title_alone_does_not_unpublish(): void
    {
        $this->save(['title' => 'عنوان جديد تمامًا'])->assertStatus(200);

        $this->assertSame(1, (int) $this->stored()['is_published']);
    }

    // ── the empty-body case ───────────────────────────────────────────────

    /**
     * An empty payload is a 200 no-op, exactly as backend/'s
     * `if (empty($fields)) return;` made it — and Eloquent issues no UPDATE at
     * all, so even `updated_at` is untouched.
     */
    public function test_an_empty_payload_changes_nothing_and_still_succeeds(): void
    {
        $before = $this->stored();

        $this->save([])->assertStatus(200)->assertJsonPath('message', 'تم تحديث الكورس بنجاح');

        $this->assertSame($before, $this->stored());
    }
}
