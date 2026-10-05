<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * teacher_profit_settings — one row per instructor, holding their revenue
 * share. Migration 2026_01_01_000027: exactly two columns,
 * `teacher_id` (PRIMARY KEY, FK -> teachers.user_id) and
 * `profit_percentage` DECIMAL(5,2) DEFAULT 70.
 *
 * NOT a PlaceholderModel, and that is a deliberate call rather than an
 * oversight. The placeholder marker means "this class is a stub whose real
 * body lands in a later stage" — but a two-column table has no body to land:
 * table name, key configuration and one cast IS the whole model. Stage 7 owns
 * the revenue LOGIC (payouts, statements, transactions), none of which belongs
 * on this class.
 *
 * NOT a BaseModel: `teacher_id` is the primary key, so HasUuids would generate
 * a stray uuid on create and break the 1:1 link — same reasoning as Teacher
 * and Student.
 *
 * TIMESTAMPS ARE OFF: the table has neither timestamp column.
 *
 * `profit_percentage` IS DECIMAL AND IS CAST TO decimal:2, NOT float. PDO
 * returns DECIMAL as a PHP string (tests/Feature/PdoTypeCastingTest.php) and
 * this value multiplies money — a float cast would introduce binary rounding
 * into a revenue split. Same treatment as Course::$price, which is likewise
 * left un-floated.
 */
class TeacherProfitSetting extends Model
{
    protected $table = 'teacher_profit_settings';

    protected $primaryKey = 'teacher_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'teacher_id',
        'profit_percentage',
    ];

    protected function casts(): array
    {
        return [
            'profit_percentage' => 'decimal:2',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id', 'user_id');
    }
}
