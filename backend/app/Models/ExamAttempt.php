<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * exam_attempts — one student's one sitting of one exam.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THE FIRST WRITE SURFACE ON THIS TABLE IN THE ENTIRE REWRITE.            ║
 * ║ STAGE 6 / SESSION B.                                                     ║
 * ║                                                                          ║
 * ║ Session A's guarantee was "no handler exists, so no locking can be got   ║
 * ║ wrong". That guarantee is spent. What replaces it is                      ║
 * ║ App\Actions\Exam\SubmitExamAttempt — the ONLY class in the application   ║
 * ║ that inserts into this table — plus the `uq_exam_attempts_ordinal`       ║
 * ║ UNIQUE key below the application layer.                                   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Columns (migration 2026_01_01_000022): id, exam_id, student_id,
 * attempt_number, answers (JSON), score DECIMAL(6,2), started_at, submitted_at.
 * NO created_at/updated_at — `started_at` is the creation clock, hence
 * `$timestamps = false`.
 *
 * ┌── NO `$fillable`, AND THAT IS THE SAME RULE AS `exams` ITSELF. ──────────┐
 * │ Every column here is either an identity taken from the SESSION           │
 * │ (`student_id`), a value taken from the ROUTE (`exam_id`), a value        │
 * │ computed by the server (`attempt_number`, `score`, both clocks) or the   │
 * │ verbatim submitted payload (`answers`). NOT ONE of them may ever be      │
 * │ writable from a request key — `score` most of all, since a mass-         │
 * │ assignable score would let a student post their own 100.00.              │
 * │                                                                          │
 * │ The Action writes with forceFill(), which states at the call site that   │
 * │ the values came from somewhere other than the request body.              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * `student_id` is an FK into `students.user_id`, NOT `users.id`. That single
 * fact is why the "only an enrolled student may submit" check exists at all:
 * an admin or an instructor has no `students` row, so an INSERT for either one
 * dies on a raw FK violation (a 500) unless something rejects them first — and
 * `Gate::before` guarantees no Policy can be that something. See
 * App\Actions\Exam\SubmitExamAttempt's banner.
 */
class ExamAttempt extends BaseModel
{
    protected $table = 'exam_attempts';

    public $timestamps = false;

    /**
     * `answers` => 'array' is the framework doing what
     * backend/ExamRepository did by hand: json_encode(…, JSON_UNESCAPED_UNICODE)
     * on the way in and json_decode(…, true) on the way out. Arabic survives the
     * round trip because App\Http\Responses\ApiResponse re-encodes the whole
     * envelope with JSON_UNESCAPED_UNICODE.
     *
     * `score` => 'decimal:2' pins the STORED precision against the column's
     * DECIMAL(6,2); the float cast the wire contract needs is applied in the
     * Resource, where presentation belongs (same split as Question::points).
     */
    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'score' => 'decimal:2',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id', 'id');
    }

    /**
     * THE ATTEMPT COUNT THAT THE CAP IS ENFORCED AGAINST.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THIS SCOPE MUST NEVER GROW A `lockForUpdate()`. IF YOU ARE ABOUT TO  ║
     * ║ ADD ONE, THAT IS THE BUG THIS WHOLE SESSION EXISTS TO AVOID.         ║
     * ║                                                                      ║
     * ║ `SELECT … FOR UPDATE` over an EMPTY result set locks NOTHING in      ║
     * ║ InnoDB's default REPEATABLE READ — there is no row to place a record ║
     * ║ lock on. A student's FIRST attempt is exactly that case, so two      ║
     * ║ concurrent first submissions would both take "no lock", both count   ║
     * ║ 0, and both insert. The serialisation point is the PARENT `exams`    ║
     * ║ row, which always exists; see App\Actions\Exam\SubmitExamAttempt.    ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     */
    public function scopeOfStudent(Builder $query, string $examId, string $studentId): Builder
    {
        return $query->where('exam_id', $examId)->where('student_id', $studentId);
    }

    /**
     * The attempt `GET /exams/{id}/results` reports on.
     *
     * ┌── `ORDER BY started_at DESC LIMIT 1`, AND NO TIE-BREAK. ─────────────┐
     * │ Ported EXACTLY from backend/ExamRepository::findAttempt(). Note that │
     * │ `started_at` and `submitted_at` are written with the same NOW() by   │
     * │ the submit path — this schema has no separate "exam opened" event —  │
     * │ so two attempts inside one second are indistinguishable here and     │
     * │ MySQL picks arbitrarily between them.                                 │
     * │                                                                      │
     * │ Adding `->orderByDesc('attempt_number')` would make that             │
     * │ deterministic AND would be a behaviour change on a route that        │
     * │ already ships. Recorded as open decision 6.12 instead of slipped in. │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    public static function latestFor(string $examId, string $studentId): ?self
    {
        return self::query()
            ->ofStudent($examId, $studentId)
            ->orderByDesc('started_at')
            ->first();
    }
}
