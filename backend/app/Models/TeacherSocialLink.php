<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * teacher_social_links — the child rows behind AddTeacher.tsx's
 * "TeacherSocialLinks" step and the icon row on TeacherProfilePage.tsx.
 *
 * `teacher_id` POINTS AT `teachers.user_id`, not at `users.id` — the FK is
 * declared `fk_teacher_social_links_teacher -> teachers(user_id)` in migration
 * 2026_01_01_000004. Same shape as Course::$teacher_id; see that model's
 * docblock for why the numerically-identical value is nevertheless a different
 * claim.
 *
 * TIMESTAMPS ARE OFF: the table has neither `created_at` nor `updated_at`
 * (verified in the migration), so Eloquent's automatic management would write
 * columns that do not exist. Same treatment as Subscription/LoginSession.
 *
 * `order_index` IS THE COLUMN; `order` IS THE WIRE NAME. The frontend's
 * SocialLink type calls it `order`, and backend/'s
 * TeacherRepository::decodeSocialLink() translated on the way out. The
 * translation stays in App\Http\Resources\SocialLink so exactly one place
 * knows about it — this model speaks the schema's language.
 */
class TeacherSocialLink extends BaseModel
{
    protected $table = 'teacher_social_links';

    public $timestamps = false;

    protected $fillable = [
        'teacher_id',
        'platform',
        'url',
        'enabled',
        'order_index',
    ];

    /**
     * `enabled` is TINYINT(1) and arrives as int 1/0 — the frontend's
     * SocialLink declares `enabled: boolean`, so the cast is load-bearing.
     * `order_index` is INT and arrives as int already; cast anyway so a driver
     * that returns strings (PDO with emulated prepares) cannot change the JSON
     * type of this field.
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'order_index' => 'integer',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id', 'user_id');
    }
}
