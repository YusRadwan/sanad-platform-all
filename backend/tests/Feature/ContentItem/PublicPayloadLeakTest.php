<?php

declare(strict_types=1);

namespace Tests\Feature\ContentItem;

use App\Enums\ContentItemType;
use App\Http\Resources\ContentItemResource;
use App\Http\Resources\PublicContentItemResource;
use App\Models\ContentItem;
use App\Models\Course;
use App\Models\Exam;
use App\Models\Link;
use App\Models\Note;
use App\Models\Section;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthFixtures;
use Tests\TestCase;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE SINGLE MOST IMPORTANT FILE IN STAGE 5 / SESSION C.                  ║
 * ║                                                                          ║
 * ║ Sessions A and B kept every content route behind `auth` so that payload  ║
 * ║ leakage was impossible BY CONSTRUCTION. Session C opens four public      ║
 * ║ reads, which trades that absolute guarantee for a structural one:        ║
 * ║ PublicContentItemResource is a SEPARATE CLASS with no code path capable  ║
 * ║ of emitting a paid field.                                                ║
 * ║                                                                          ║
 * ║ THIS FILE IS THE PROOF OF THAT CLAIM, AND IT IS ADVERSARIAL BY DESIGN:  ║
 * ║                                                                          ║
 * ║  * ALL FIVE content types (video, link, note, exam, file),              ║
 * ║    each with a REAL detail row and EVERY nullable column filled with a  ║
 * ║    distinctive, unmistakable sentinel value.                            ║
 * ║  * The detail relations are EAGER-LOADED before serialisation, exactly  ║
 * ║    as the controller loads them — so the data is genuinely present in   ║
 * ║    memory and the Resource's silence is a real property, not an         ║
 * ║    artefact of an unhydrated model.                                      ║
 * ║  * Assertions are made on KEYS **and** on VALUES, RECURSIVELY, over the ║
 * ║    raw JSON STRING. A key-only check misses a leak that renames; a      ║
 * ║    top-level check misses a leak one level down; a structured check     ║
 * ║    misses a leak that stringifies. The raw-string substring search      ║
 * ║    catches all three and cannot be satisfied by accident.               ║
 * ║  * The same fixtures are then pushed through the LIVE HTTP ROUTES as a  ║
 * ║    guest, because a Resource that is clean in isolation proves nothing  ║
 * ║    if a controller ships the private one.                                ║
 * ║                                                                          ║
 * ║ THE CONTROL TEST AT THE BOTTOM IS NOT OPTIONAL. It asserts that         ║
 * ║ ContentItemResource — the PRIVATE one, over the identical fixtures —    ║
 * ║ DOES emit every one of these values. Without it, this whole file would  ║
 * ║ pass just as happily against fixtures that were silently empty, and the ║
 * ║ suite would be proving nothing while looking rigorous.                   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class PublicPayloadLeakTest extends TestCase
{
    use CreatesAuthFixtures;
    use RefreshDatabase;

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THE FORBIDDEN SET. EVERY KEY THAT MAY NEVER REACH AN UNENTITLED     ║
     * ║ CALLER, WITH THE SENTINEL VALUE THE FIXTURE STORES IN IT.           ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * The values are deliberately weird strings/numbers that cannot occur by
     * chance in a JSON envelope, so a substring search over the serialised
     * payload is a sound test rather than a probabilistic one.
     *
     * @var array<string, list<string>>  type => [forbidden values]
     */
    private const FORBIDDEN_VALUES = [
        'video' => ['https://leak.invalid/PAID-STREAM-URL', 'LEAKPROVIDER'],
        'link' => ['https://leak.invalid/PAID-LINK-TARGET'],
        'note' => ['LEAKED-NOTE-BODY-TEXT', 'LEAKED-FILE-NAME.pdf', 'notes/LEAKED-FILE-PATH.pdf'],
        'exam' => ['77.77', '88.88'],
    ];

    /** Keys that must not appear ANYWHERE in a public payload, for ANY type. */
    private const FORBIDDEN_KEYS = [
        // The container itself. Its absence is what makes the rest structural.
        'detail',
        // videos
        'video_url', 'videoUrl', 'provider',
        // links
        'url',
        // notes
        'content_text', 'contentText', 'file_path', 'filePath', 'file_name', 'fileName',
        // exams
        'pass_score', 'passScore', 'total_score', 'totalScore',
        'question_group_id', 'questionGroupId', 'duration_minutes', 'durationMinutes',
        'allowed_attempts', 'allowedAttempts', 'randomize_questions', 'randomizeQuestions',
        'lock_content', 'lockContent', 'show_answers', 'showAnswers',
        'correct_answer', 'correctAnswer',
        'max_score', 'maxScore', 'due_at', 'dueAt',
    ];

    /** The ONLY keys a public payload may carry. Anything else is a leak. */
    private const ALLOWED_KEYS_BASE = [
        'id', 'section_id', 'group_id', 'course_id', 'courseId', 'title', 'type',
        'order_index', 'is_hidden', 'isHidden', 'description', 'start_at', 'end_at',
    ];

    /** …plus these four, and ONLY for `type = video`. */
    private const ALLOWED_KEYS_VIDEO_EXTRA = ['duration', 'isFree', 'isPreview', 'thumbnailUrl'];

    private User $owner;

    private Course $course;

    private Section $section;

    /** @var array<string, ContentItem> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableRateLimiting();

        [$this->owner] = $this->makeTeacherWithProfile();
        $this->course = $this->makeCourseFor($this->owner, ['is_published' => true]);
        $this->section = $this->makeSection($this->course);

        $this->items = $this->buildOneOfEveryType();
    }

    /**
     * One content item PER TYPE, each with EVERY writable detail column filled.
     *
     * ┌── WHY THE DETAIL ROWS ARE WRITTEN WITH setAttribute AND NOT THE ─────┐
     * │ PERSISTERS. The persisters are the production write path and they    │
     * │ VALIDATE — SafeUrlValue would reject a deliberately absurd URL, and  │
     * │ PersistNoteDetail declines to create a row at all when nothing was   │
     * │ sent. This test needs the WORST CASE: every column non-null,         │
     * │ including combinations the API would refuse to create. Building them │
     * │ by hand is the only way to ask "if such a row existed, could it      │
     * │ escape?" — which is the question that matters, because a row can     │
     * │ arrive from a data migration, an admin fix, or a future write path.  │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * @return array<string, ContentItem>
     */
    private function buildOneOfEveryType(): array
    {
        $items = [];

        foreach (ContentItemType::cases() as $type) {
            $items[$type->value] = $this->makeContentItem($this->section, [
                'type' => $type,
                'title' => "عنوان {$type->value}",
                'description' => 'وصف عام يجوز نشره',
                'start_at' => '2026-03-01 08:00:00',
                'end_at' => '2026-03-31 20:00:00',
            ]);
        }

        $video = new Video();
        $video->setAttribute('content_item_id', $items['video']->getKey());
        $video->setAttribute('video_url', 'https://leak.invalid/PAID-STREAM-URL');
        $video->setAttribute('duration', 3671);
        $video->setAttribute('is_preview', true);
        $video->setAttribute('provider', 'LEAKPROVIDER');
        $video->setAttribute('thumbnail_url', 'https://cdn.example.test/poster.jpg');
        $video->save();

        $link = new Link();
        $link->setAttribute('content_item_id', $items['link']->getKey());
        $link->setAttribute('url', 'https://leak.invalid/PAID-LINK-TARGET');
        $link->save();

        $note = new Note();
        $note->setAttribute('content_item_id', $items['note']->getKey());
        $note->setAttribute('content_text', 'LEAKED-NOTE-BODY-TEXT');
        $note->setAttribute('file_name', 'LEAKED-FILE-NAME.pdf');
        $note->setAttribute('file_path', 'notes/LEAKED-FILE-PATH.pdf');
        $note->save();

        $exam = new Exam();
        $exam->setAttribute('content_item_id', $items['exam']->getKey());
        $exam->setAttribute('duration_minutes', 45);
        $exam->setAttribute('pass_score', '77.77');
        $exam->setAttribute('total_score', '88.88');
        $exam->setAttribute('allowed_attempts', 3);
        $exam->setAttribute('randomize_questions', true);
        $exam->setAttribute('lock_content', true);
        $exam->setAttribute('show_answers', true);
        $exam->save();

        // `file` gets NOTHING — that is approved decision #1, and its presence
        // here proves the Resource handles a detail-less type without a
        // fallthrough that might reach for another relation.

        return $items;
    }

    // ══ 1. THE RESOURCE ITSELF, ALL SIX TYPES ════════════════════════════

    /**
     * THE CENTREPIECE. Serialise PublicContentItemResource against a fully
     * populated instance of every content type and assert, over the RAW JSON
     * STRING, that not one forbidden key and not one forbidden value survives.
     */
    public function test_the_public_resource_leaks_nothing_for_any_of_the_six_types(): void
    {
        foreach ($this->items as $type => $item) {
            $json = $this->publicJsonFor($item);

            foreach (self::FORBIDDEN_KEYS as $key) {
                $this->assertStringNotContainsString(
                    '"' . $key . '"',
                    $json,
                    "PublicContentItemResource leaked the KEY `{$key}` for type `{$type}`.\n{$json}"
                );
            }

            foreach (self::FORBIDDEN_VALUES as $owningType => $values) {
                foreach ($values as $value) {
                    $this->assertStringNotContainsString(
                        $value,
                        $json,
                        "PublicContentItemResource leaked the {$owningType} VALUE `{$value}` "
                        . "while serialising type `{$type}`.\n{$json}"
                    );
                }
            }
        }
    }

    /**
     * THE ALLOW-LIST HALF, and it is the stronger of the two.
     *
     * The test above can only catch a leak somebody thought to name. This one
     * catches a leak NOBODY thought of: it asserts the emitted key set is
     * EXACTLY the permitted set, so a column added to `videos` in Stage 7 and
     * casually dumped into the payload fails here without anyone having had to
     * predict it.
     */
    public function test_the_public_payload_carries_exactly_the_allowed_keys_and_no_others(): void
    {
        foreach ($this->items as $type => $item) {
            $payload = $this->publicArrayFor($item);

            $expected = $type === 'video'
                ? [...self::ALLOWED_KEYS_BASE, ...self::ALLOWED_KEYS_VIDEO_EXTRA]
                : self::ALLOWED_KEYS_BASE;

            sort($expected);
            $actual = array_keys($payload);
            sort($actual);

            $this->assertSame(
                $expected,
                $actual,
                "The public key set for type `{$type}` is not the permitted set."
            );

            // Nothing nested, either — a leak cannot hide inside an object.
            foreach ($payload as $key => $value) {
                $this->assertFalse(
                    is_array($value),
                    "Public key `{$key}` on type `{$type}` is a nested structure; the public "
                    . 'DTO is deliberately flat, and nesting is where a payload hides.'
                );
            }
        }
    }

    /**
     * The five NON-video types expose ZERO detail-derived fields — not even a
     * `duration: null`-shaped hint that something is being withheld.
     */
    public function test_the_five_non_video_types_expose_no_detail_derived_field_at_all(): void
    {
        foreach ($this->items as $type => $item) {
            if ($type === 'video') {
                continue;
            }

            $keys = array_keys($this->publicArrayFor($item));

            foreach (self::ALLOWED_KEYS_VIDEO_EXTRA as $videoOnly) {
                $this->assertNotContains(
                    $videoOnly,
                    $keys,
                    "Type `{$type}` emitted the video-only key `{$videoOnly}`."
                );
            }

            $this->assertSame(self::ALLOWED_KEYS_BASE, $keys);
        }
    }

    /** A video exposes exactly three teaser values — and never the stream. */
    public function test_a_video_exposes_duration_preview_and_poster_and_nothing_else(): void
    {
        $payload = $this->publicArrayFor($this->items['video']);

        $this->assertSame(3671, $payload['duration']);
        $this->assertTrue($payload['isPreview']);
        $this->assertTrue($payload['isFree'], 'isFree and isPreview are the same column; both ship.');
        $this->assertSame('https://cdn.example.test/poster.jpg', $payload['thumbnailUrl']);

        $this->assertArrayNotHasKey('videoUrl', $payload);
        $this->assertArrayNotHasKey('video_url', $payload);
        $this->assertArrayNotHasKey('provider', $payload);
    }

    /**
     * A video whose `videos` row does not exist — legal, and the shape holds.
     * backend/ answered the four keys with null/false rather than omitting
     * them, so the client's `item.duration` access does not become undefined.
     */
    public function test_a_video_without_a_detail_row_still_answers_the_four_teaser_keys(): void
    {
        $bare = $this->makeContentItem($this->section, ['type' => ContentItemType::Video]);

        $payload = $this->publicArrayFor($bare);

        $this->assertNull($payload['duration']);
        $this->assertFalse($payload['isPreview']);
        $this->assertFalse($payload['isFree']);
        $this->assertNull($payload['thumbnailUrl']);
    }

    // ══ 2. THE CONTROL — THE PRIVATE RESOURCE *DOES* EMIT ALL OF IT ══════

    /**
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ WITHOUT THIS TEST, EVERYTHING ABOVE IS WORTHLESS.                    ║
     * ║                                                                      ║
     * ║ Every assertion so far is of the form "X is absent". All of them     ║
     * ║ pass trivially against fixtures that were never populated — a typo   ║
     * ║ in setUp(), a detail row that silently failed to save, a relation    ║
     * ║ that was never loaded. The suite would look rigorous and prove       ║
     * ║ nothing.                                                             ║
     * ║                                                                      ║
     * ║ So: the SAME rows, through the PRIVATE Resource, must contain EVERY  ║
     * ║ forbidden value. That establishes the data is really there and       ║
     * ║ really reachable — which is what makes its absence upstairs a        ║
     * ║ property of PublicContentItemResource rather than of the fixture.    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function test_control_the_private_resource_does_emit_every_forbidden_value(): void
    {
        /*
         * ┌── THE ACTOR HAS TO BE SET SINCE STAGE 6 / SESSION A. ────────────┐
         * │ ContentItemResource's exam arm no longer writes                   │
         * │ the columns out by hand — they route through                      │
         * │ App\Http\Resources\ExamResourceFactory, which asks the Gate who   │
         * │ the caller is. A bare `request()` in a unit-style call carries NO │
         * │ user, so the factory correctly degrades to the PUBLIC shape and   │
         * │ this control would fail — not because the fixture is empty, but   │
         * │ because the resource is doing its job.                            │
         * │                                                                  │
         * │ Resolving the OWNER is what makes the control mean what it        │
         * │ claims: "these values ARE reachable through the private resource  │
         * │ by someone entitled to them", which is the premise every          │
         * │ absence-assertion above depends on. That the same call answers a  │
         * │ smaller payload for a null user is itself reassuring, and is      │
         * │ asserted separately below.                                        │
         * └──────────────────────────────────────────────────────────────────┘
         */
        $request = request();
        $request->setUserResolver(fn (): User => $this->owner);

        foreach (self::FORBIDDEN_VALUES as $type => $values) {
            $item = $this->items[$type];
            $item->unsetRelations();
            ContentItem::loadDetailsFor(new \Illuminate\Database\Eloquent\Collection([$item]));

            $json = (string) json_encode(
                (new ContentItemResource($item))->toArray($request),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            foreach ($values as $value) {
                $this->assertStringContainsString(
                    $value,
                    $json,
                    "CONTROL FAILED: the private resource did NOT emit `{$value}` for type "
                    . "`{$type}`, which means the fixture is empty and every leak assertion in "
                    . 'this file is vacuous.'
                );
            }

            $this->assertArrayHasKey('detail', (new ContentItemResource($item))->toArray($request));
        }
    }

    // ══ 3. THE SAME PROOF OVER THE LIVE HTTP ROUTES ══════════════════════

    /**
     * A clean Resource proves nothing if a controller ships the other one.
     *
     * Every public read, as a GUEST, over the real fixtures — the raw response
     * body searched for every forbidden value. This is the end-to-end claim:
     * "no anonymous request to this application can obtain a paid field".
     */
    public function test_no_public_route_leaks_a_paid_value_to_a_guest(): void
    {
        $urls = [
            "/api/v1/sections/{$this->section->getKey()}/content-items",
            "/api/v1/courses/{$this->course->getKey()}/structure",
            "/api/v1/courses/{$this->course->getKey()}/videos",
        ];

        foreach ($this->items as $item) {
            $urls[] = "/api/v1/content-items/{$item->getKey()}";
        }

        foreach ($urls as $url) {
            $this->flushSession();
            $this->startNextRequest();

            $body = $this->getJson($url)->assertOk()->getContent() ?: '';

            foreach (self::FORBIDDEN_VALUES as $type => $values) {
                foreach ($values as $value) {
                    $this->assertStringNotContainsString(
                        $value,
                        $body,
                        "GET {$url} leaked the {$type} value `{$value}` to a GUEST."
                    );
                }
            }

            foreach (['detail', 'video_url', 'content_text', 'file_path', 'pass_score'] as $key) {
                $this->assertStringNotContainsString(
                    '"' . $key . '"',
                    $body,
                    "GET {$url} leaked the key `{$key}` to a GUEST."
                );
            }
        }
    }

    /**
     * …and the same, for the three OTHER unentitled actors. A guest is the
     * obvious case; an EXPIRED subscriber is the one a naive
     * `subscriptions.exists()` check would let through.
     */
    public function test_no_unentitled_actor_obtains_a_paid_value_on_any_public_route(): void
    {
        $unsubscribed = $this->makeStudentUser();

        $expired = $this->makeStudentUser();
        $this->makeSubscription($expired, $this->course, expiresAt: now()->subDay());

        $cancelled = $this->makeStudentUser();
        $this->makeSubscription($cancelled, $this->course, \App\Enums\SubscriptionStatus::Cancelled);

        $stranger = $this->makeTeacherWithProfile()[0];

        foreach ([$unsubscribed, $expired, $cancelled, $stranger] as $actor) {
            foreach ($this->items as $item) {
                $this->flushSession();
                $this->startNextRequest();
                $this->loginAs($actor);
                $this->startNextRequest();

                $body = $this->getJson("/api/v1/content-items/{$item->getKey()}")
                    ->assertOk()
                    ->getContent() ?: '';

                foreach (self::FORBIDDEN_VALUES as $values) {
                    foreach ($values as $value) {
                        $this->assertStringNotContainsString(
                            $value,
                            $body,
                            "An unentitled actor (role {$actor->role->value}) obtained `{$value}`."
                        );
                    }
                }
            }
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * Serialise through the public Resource with the detail relation EAGERLY
     * LOADED — exactly as ContentItemController::present() loads it.
     *
     * The load is the point. Testing an unhydrated model would prove only that
     * Eloquent does not lazy-load inside a Resource; loading it first asks the
     * real question: the data IS in memory, and the class still cannot emit it.
     *
     * @return array<string, mixed>
     */
    private function publicArrayFor(ContentItem $item): array
    {
        $item->unsetRelations();
        ContentItem::loadDetailsFor(new \Illuminate\Database\Eloquent\Collection([$item]));

        return (new PublicContentItemResource($item))->toArray(request());
    }

    private function publicJsonFor(ContentItem $item): string
    {
        return (string) json_encode(
            $this->publicArrayFor($item),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
