<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Exam;
use App\Models\ExamAttempt;

/**
 * The NINE keys both result shapes share, in one place.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THIS IS NOT A BASE CLASS, AND THAT IS THE POINT.                        ║
 * ║                                                                          ║
 * ║ The two Resources that use it — ExamResultResource and                  ║
 * ║ RevealedExamResultResource — are structurally separate precisely so that ║
 * ║ neither can grow the other's fields by accident. Sharing the common      ║
 * ║ nine through INHERITANCE would undo that: a key added to a parent        ║
 * ║ appears in both children with no diff on the file anybody reviews.       ║
 * ║                                                                          ║
 * ║ A plain function called BY both is different in the way that matters:    ║
 * ║ the one dangerous key, `correct_answers`, is written in exactly one      ║
 * ║ class, and this helper has no line of code able to produce it.           ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Field-by-field against backend/ExamService::resultsFor() (~lines 225-235):
 *
 *   attempt_id      the row id
 *   attempt_number  int, defaulting to 1 — the column has DEFAULT 1
 *   score           FLOAT on the wire. DECIMAL(6,2) comes back from PDO as a
 *                   string and the model's `decimal:2` cast keeps it one; the
 *                   float cast belongs here, where presentation does.
 *   answers         the decoded payload; NULL becomes [], never null, matching
 *                   backend/'s `json_decode($attempt['answers'] ?? '[]')`
 *   started_at      'Y-m-d H:i:s' — the raw MySQL DATETIME string, NOT
 *   submitted_at    ISO-8601. Same decision, same reason, as every other
 *                   date in this application (see CurriculumResource).
 *   pass_score      float or null
 *   passed          NULL when the exam sets no pass mark — a tri-state, not a
 *                   false. A client showing "راسب" for an ungraded exam is
 *                   exactly what the null prevents.
 *   show_answers    the reveal DECISION, not the column. See
 *                   ExamResultResource's note.
 */
final class ExamResultPayload
{
    /** @return array<string, mixed> */
    public static function of(ExamAttempt $attempt, Exam $exam, bool $showAnswers): array
    {
        $score = (float) $attempt->getAttribute('score');
        $passScore = $exam->getAttribute('pass_score');
        $passScore = $passScore === null ? null : (float) $passScore;

        return [
            'attempt_id' => $attempt->getKey(),
            'attempt_number' => (int) ($attempt->getAttribute('attempt_number') ?? 1),
            'score' => $score,
            'answers' => $attempt->getAttribute('answers') ?? [],
            'started_at' => $attempt->getAttribute('started_at')?->format('Y-m-d H:i:s'),
            'submitted_at' => $attempt->getAttribute('submitted_at')?->format('Y-m-d H:i:s'),
            'pass_score' => $passScore,
            'passed' => $passScore === null ? null : $score >= $passScore,
            'show_answers' => $showAnswers,
        ];
    }

    /** Static-only. */
    private function __construct()
    {
    }
}
