<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ContentItem;
use App\Models\Exam;
use App\Models\Link;
use App\Models\Note;
use App\Models\Video;
use Illuminate\Http\Request;
// NOTE: ExamResourceFactory needs no `use` — it lives in THIS namespace.
// Stage 6 / Session A routes the exam arm of detail() through it instead of
// serialising the raw model; see detail()'s banner for the leak that closes.
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Port of backend/ContentItemService::present() — the ENTITLED representation,
 * `detail` payload included.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS IS THE **PRIVATE** DTO. THERE IS NO PUBLIC ONE YET, AND THAT IS    ║
 * ║ THE POINT OF THIS SESSION'S SCOPE.                                      ║
 * ║                                                                          ║
 * ║ backend/ has two presenters: present() (with `detail`) and               ║
 * ║ presentPublic() (structurally incapable of carrying video_url /          ║
 * ║ file_path / content_text / a link target / exam config, because those    ║
 * ║ keys simply do not exist in the array it returns). Session C builds the  ║
 * ║ second one and the routes that need it.                                  ║
 * ║                                                                          ║
 * ║ Every route in Session B is behind `auth` + `role:admin,instructor` AND  ║
 * ║ behind an owner-or-admin gate, so every caller that can reach this class ║
 * ║ has already been proven entitled. That is why shipping only the private  ║
 * ║ shape is safe here and why no `when()`/`$entitled` flag is threaded      ║
 * ║ through it: a conditional payload is a leak waiting for the condition to ║
 * ║ be evaluated wrongly, and the condition does not exist yet. Session C    ║
 * ║ adds a SEPARATE Resource class rather than a branch in this one.         ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── THE DUPLICATED `is_hidden`/`isHidden` PAIR IS THE CONTRACT. ───────────┐
 * │ present() emits BOTH spellings of the same column. videoApiService.ts    │
 * │ and linkApiService.ts read `item.is_hidden`; other components read       │
 * │ `isHidden`. Dropping either breaks a live consumer, so both ship — this  │
 * │ is a ported duplication, not a new one, and it must not spread.          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌── DATETIMES ARE 'Y-m-d H:i:s', NOT ISO-8601. ───────────────────────────┐
 * │ Same decision, same reason, as TeacherResource::created_at: backend/     │
 * │ shipped the raw MySQL DATETIME string. It matters concretely here —      │
 * │ videoApiService.ts's splitDateTime() does                                │
 * │   String(value).split(/[T ]/)                                            │
 * │ and then `time.slice(0, 5)`. Laravel's default cast serialisation would  │
 * │ emit '2026-01-01T10:00:00.000000Z', which parses but drags every         │
 * │ timestamp through a UTC conversion the legacy payload never applied.     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * @mixin ContentItem
 */
final class ContentItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ContentItem $item */
        $item = $this->resource;

        return [
            'id' => $item->getKey(),
            'section_id' => $item->getAttribute('section_id'),
            'group_id' => $item->getAttribute('group_id'),
            'course_id' => $item->getAttribute('course_id'),
            'title' => $item->getAttribute('title'),
            'type' => $item->getAttribute('type')?->value,
            'order_index' => (int) $item->getAttribute('order_index'),
            'is_hidden' => (bool) $item->getAttribute('is_hidden'),
            'isHidden' => (bool) $item->getAttribute('is_hidden'),
            'description' => $item->getAttribute('description'),
            'start_at' => $item->getAttribute('start_at')?->format('Y-m-d H:i:s'),
            'end_at' => $item->getAttribute('end_at')?->format('Y-m-d H:i:s'),
            // $request is threaded through so the exam/assignment arms can ask
            // the entitlement question at FIELD level — see detail().
            'detail' => $this->detail($request, $item),
        ];
    }

    /**
     * The type-specific payload, or null.
     *
     * NULL IS A REAL, EXPECTED ANSWER in three distinct situations, and the
     * frontend already treats all three the same way (`item.detail || {}`):
     *
     *   1. `type = 'file'`   — no detail table exists (decision #1).
     *   2. a note with neither content_text nor file_path — no row was ever
     *      created (see PersistNoteDetail).
     *   3. a video/link created without its URL — likewise no row.
     *
     * The keys below are written out per type rather than dumped from
     * `$model->toArray()`: a dump would silently start shipping any column a
     * later migration adds to `exams` — the answer key, for instance — into a
     * payload that is now, but may not stay, owner-only.
     *
     * @return array<string, mixed>|null
     */
    private function detail(Request $request, ContentItem $item): ?array
    {
        $detail = $item->detail();

        /*
         * THE 1:1 IS RESOLVED BY HAND, NOT BY A QUERY.
         *
         * The exam/assignment Resources read `title` and `course_id` off the
         * parent content item, and ExamResourceFactory asks a policy that walks
         * exam -> contentItem -> course. We ALREADY HOLD that parent — it is
         * `$item` — and `uq_exams_content_item` / `uq_assignments_content_item`
         * make the relationship strictly 1:1, so seeding the relation is exact
         * rather than an optimisation with an edge case. Without it every exam
         * in a list costs an extra `content_items` SELECT to re-fetch the row
         * it was reached through.
         */
        if ($detail instanceof Exam) {
            $detail->setRelation('contentItem', $item);
        }

        return match (true) {
            $detail instanceof Video => [
                'content_item_id' => $detail->getKey(),
                'video_url' => $detail->getAttribute('video_url'),
                'duration' => $detail->getAttribute('duration'),
                'is_preview' => (bool) $detail->getAttribute('is_preview'),
                'provider' => $detail->getAttribute('provider'),
                'thumbnail_url' => $detail->getAttribute('thumbnail_url'),
            ],
            $detail instanceof Link => [
                'content_item_id' => $detail->getKey(),
                'url' => $detail->getAttribute('url'),
            ],
            $detail instanceof Note => [
                'content_item_id' => $detail->getKey(),
                'content_text' => $detail->getAttribute('content_text'),
                'file_name' => $detail->getAttribute('file_name'),
                'file_path' => $detail->getAttribute('file_path'),
            ],
            /*
             * ╔══════════════════════════════════════════════════════════════╗
             * ║ STAGE 6 / SESSION A — THE BACKDOOR, CLOSED.                  ║
             * ║                                                              ║
             * ║ These two arms USED TO WRITE THE COLUMNS OUT BY HAND, and    ║
             * ║ the exam arm included `question_group_id`. So this Resource   ║
             * ║ was a SECOND DOOR to a row that GET /exams/{id} also serves — ║
             * ║ with a DIFFERENT key set, reached by a different route, and   ║
             * ║ governed by a different (i.e. absent) entitlement rule. Two   ║
             * ║ doors to one row with two shapes is the SHAPE OF EVERY        ║
             * ║ PAYLOAD LEAK: the route that gets tightened gets the review,  ║
             * ║ and the older one keeps shipping.                             ║
             * ║                                                              ║
             * ║ Concretely, before this change a SUBSCRIBED STUDENT fetching  ║
             * ║ an exam through GET /content-items/{id} received              ║
             * ║ `question_group_id` — the very field approved decision #3     ║
             * ║ removes from GET /exams/{id}. Routing both through the ONE    ║
             * ║ factory makes the two doors the same door.                    ║
             * ║                                                              ║
             * ║ `$request->user()` is the actor. This Resource is only ever   ║
             * ║ built on the ENTITLED branch (see ContentItemController::     ║
             * ║ present()), so the factory can still answer STAFF for the     ║
             * ║ owner and ENROLLED for the subscriber — the entitlement       ║
             * ║ question is asked again, at field level, rather than being    ║
             * ║ assumed from the branch that got us here.                     ║
             * ║                                                              ║
             * ║ Pinned by ExamPayloadSplitTest::test_the_same_exam_has_the_   ║
             * ║ same_key_set_through_all_four_read_paths.                     ║
             * ╚══════════════════════════════════════════════════════════════╝
             *
             * NOTE the wire differences this unification necessarily makes,
             * both recorded in docs/open-decisions.md rather than passed over:
             *   + `title` and `course_id` are now present in the detail (they
             *     were only ever emitted by the /exams routes);
             *   ~ `pass_score`/`total_score`/`max_score` are now JSON NUMBERS
             *     rather than the decimal-as-string PDO gives back. Both
             *     shapes existed in backend/ on different routes; the numeric
             *     one wins because the consumer of the OTHER shape applies
             *     Number() anyway, while the exam pages read these as numbers.
             */
            $detail instanceof Exam => ExamResourceFactory::for($request->user(), $detail)
                ->toArray($request),
            default => null,
        };
    }
}
