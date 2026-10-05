<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoginSessionStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * login_sessions — the user-visible login history rendered by
 * frontend/src/components/.../LoginActivity.tsx.
 *
 * This is a DIFFERENT THING from the PHP/Laravel session. The session is the
 * cookie-backed auth state; a `login_sessions` row is an audit record ("this
 * account signed in from this IP with this user agent at this time"). One login
 * creates exactly one row, whose id is remembered in the session so logout can
 * flip its status.
 *
 * TIMESTAMPS ARE NON-STANDARD: the table has `created_at` and
 * `last_activity_at`, but NO `updated_at`. Eloquent's automatic timestamp
 * management is therefore disabled ($timestamps = false) and the two columns
 * are written explicitly — otherwise every save would try to UPDATE a column
 * that does not exist.
 *
 * `status` is backed by App\Enums\LoginSessionStatus, whose VALUES contain
 * spaces and mixed case ('Logged Out') because they are shipped verbatim to the
 * frontend and rendered as-is. See that enum before "tidying" anything.
 */
class LoginSession extends BaseModel
{
    protected $table = 'login_sessions';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'status',
        'created_at',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => LoginSessionStatus::class,
            'created_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
