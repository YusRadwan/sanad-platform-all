<?php

declare(strict_types=1);

namespace App\Actions\ContentItem;

use App\Models\ContentItem;
use App\Models\Exam;

/**
 * `exams` — port of saveTypeDetail()'s exam branch + ExamRepository::create()
 * and ::update().
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE ONE PLACE THE "null MEANS DON'T TOUCH" RULE ACTUALLY LIVES.         ║
 * ║                                                                          ║
 * ║   $existing                                                              ║
 * ║     ? update($existing['id'], array_filter($fields, fn ($v) => $v !== null))║
 * ║     : create(array_merge(['content_item_id' => $id], $fields));           ║
 * ║                                                                          ║
 * ║ CREATE passes every field INCLUDING the nulls, and the repository's own  ║
 * ║ `?? 30 / ?? 0 / ?? 1` defaults turn them into the column defaults.       ║
 * ║ UPDATE strips the nulls first, so a payload carrying only `pass_score`   ║
 * ║ leaves duration_minutes, allowed_attempts and the three flags alone.     ║
 * ║                                                                          ║
 * ║ Eloquent's updateOrCreate() CANNOT express this: it applies one          ║
 * ║ attribute array to both paths. Hence the explicit branch below rather    ║
 * ║ than a one-liner that would be subtly wrong in one of the two cases.     ║
 * ║                                                                          ║
 * ║ CONSEQUENCE, and it is the interesting one: an exam flag can be turned   ║
 * ║ ON and never OFF through this route when the value arrives as null. It   ║
 * ║ CAN be turned off by sending `false`, because the legacy branch is       ║
 * ║ `array_key_exists(…) ? (int) $data[…] : null` — false becomes int 0,     ║
 * ║ which survives array_filter's `!== null`. That distinction is ported     ║
 * ║ exactly; see the presence-based reads below.                             ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * UNIQUE(content_item_id) makes the 1:1 structural: findByContentItem() is the
 * existence probe, and a second row for the same item is rejected by
 * uq_exams_content_item rather than silently shadowing the first in the
 * batch-loader's keyed map.
 *
 * NO $fillable ON App\Models\Exam — every column is written with setAttribute
 * here, so no request key can reach a column that has not been listed in this
 * file. Exam is still a Stage 6 placeholder and must stay thin.
 */
final class PersistExamDetail implements PersistsContentDetail
{
    public function persist(ContentItem $item, array $data): void
    {
        $exam = Exam::query()->where('content_item_id', $item->getKey())->first();

        $fields = [
            'question_group_id' => $data['question_group_id'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'pass_score' => $data['pass_score'] ?? null,
            'total_score' => $data['total_score'] ?? null,
            'allowed_attempts' => $data['allowed_attempts'] ?? null,
            // array_key_exists, NOT ??: `false` is a real instruction to turn
            // the flag off and must not be collapsed into "absent".
            'randomize_questions' => array_key_exists('randomize_questions', $data)
                ? (bool) $data['randomize_questions'] : null,
            'lock_content' => array_key_exists('lock_content', $data)
                ? (bool) $data['lock_content'] : null,
            'show_answers' => array_key_exists('show_answers', $data)
                ? (bool) $data['show_answers'] : null,
        ];

        if ($exam === null) {
            $exam = new Exam();
            $exam->setAttribute('content_item_id', $item->getKey());

            // ExamRepository::create()'s own defaults, restated here because
            // they are NOT all the column defaults: duration_minutes is NOT
            // NULL, so binding an explicit null would be a 1048 rather than a
            // fallback to 30.
            $fields['duration_minutes'] ??= 30;
            $fields['randomize_questions'] ??= false;
            $fields['lock_content'] ??= false;
            $fields['show_answers'] ??= true;
        } else {
            // array_filter's exact predicate — `!== null`, so 0/false/'' all
            // survive and are written.
            $fields = array_filter($fields, static fn (mixed $v): bool => $v !== null);
        }

        foreach ($fields as $column => $value) {
            $exam->setAttribute($column, $value);
        }

        $exam->save();
    }
}
