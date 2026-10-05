<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * questions — one item in a teacher's question bank.
 *
 * Columns (migration 2026_01_01_000020): id, question_group_id, body, type,
 * options, image_url, correct_answer, points. NO timestamps at all — not even
 * created_at — so `$timestamps = false` and the UUIDv7 key is the only
 * creation-order signal there is.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ THERE IS NO QuestionPolicy, AND THERE MUST NOT BE ONE.                  ║
 * ║                                                                          ║
 * ║ A question is only ever reached THROUGH its group:                       ║
 * ║ backend/QuestionBankService::updateQuestion()/deleteQuestion() both      ║
 * ║ resolve `question -> question_group_id -> getOwnedGroup()`, so the group ║
 * ║ is the single authorization boundary. A second policy on this model      ║
 * ║ would be a second boundary that could one day disagree with the first —  ║
 * ║ App\Policies\QuestionGroupPolicy's docblock says so explicitly, and      ║
 * ║ App\Support\AssessmentAccess::assertQuestionManageable() is how the      ║
 * ║ delegation is expressed instead.                                         ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * ┌── `question_group_id` IS **DELIBERATELY ABSENT** FROM $fillable. ────────┐
 * │ It is the ownership hop. If it were mass-assignable, `PATCH             │
 * │ /questions/{id}` with a `question_group_id` in the body would let a     │
 * │ teacher MOVE a question they own into another teacher's bank — the      │
 * │ ownership check having already passed against the OLD parent. The       │
 * │ parent is set once, at creation, from the route segment that was        │
 * │ authorised. backend/QuestionRepository::update()'s field ladder has no  │
 * │ branch for it either, so this is the ported behaviour AND the safe one. │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
class Question extends BaseModel
{
    protected $table = 'questions';

    public $timestamps = false;

    /** See the banner: the parent group is never mass-assignable. */
    protected $fillable = [
        'title',
        'body',
        'type',
        'options',
        'choice_format',
        'image_url',
        'correct_answer',
        'points',
    ];

    /**
     * ┌── `options` => 'array' AND `points` => 'decimal:2'. BOTH ARE THE ────┐
     * │ CONTRACT, AND BOTH REPLACE HAND-ROLLED LEGACY CONVERSIONS.           │
     * │                                                                      │
     * │ options   backend/QuestionRepository json_encode()s on the way in    │
     * │           (with JSON_UNESCAPED_UNICODE) and json_decode(…, true)s in │
     * │           its private decode() on the way out. The 'array' cast is   │
     * │           exactly that pair, applied by the framework, so there is   │
     * │           no path on which a row is read without being decoded.      │
     * │           Arabic survives because App\Http\Responses\ApiResponse     │
     * │           re-encodes the whole envelope with JSON_UNESCAPED_UNICODE. │
     * │                                                                      │
     * │ points    DECIMAL(6,2). PDO hands DECIMAL back as a PHP **string**   │
     * │           (measured — tests/Feature/PdoTypeCastingTest.php), and the │
     * │           legacy presenter cast it to float on the way out. The      │
     * │           'decimal:2' cast pins the STORED precision (so `1` is      │
     * │           written as 1.00 and read back as "1.00" rather than as     │
     * │           whatever MySQL's client happens to return); the float cast │
     * │           that the wire contract needs is applied in the Resource,   │
     * │           which is where presentation belongs.                       │
     * └──────────────────────────────────────────────────────────────────────┘
     */
    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'options' => 'array',
            'points' => 'decimal:2',
        ];
    }

    /** The owning bank — the ONLY authorization boundary for this row. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(QuestionGroup::class, 'question_group_id', 'id');
    }
}
