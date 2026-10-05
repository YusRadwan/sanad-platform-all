<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * users.
 *
 * Extends Illuminate\Foundation\Auth\User (not BaseModel) so Stage 1 can use
 * Laravel's native session guard without rewriting this class — but it repeats
 * BaseModel's UUID configuration rather than inheriting it, since PHP has no
 * multiple inheritance. Keep the two in sync.
 *
 * WHY `password_hash` IS NOT RENAMED TO `password`
 * ------------------------------------------------------------------
 * Laravel's guard reads the hash through getAuthPassword(), which is an
 * explicit first-party extension point. Overriding one method is strictly
 * cheaper and safer than renaming a production column the legacy backend still
 * reads. `password_hash` also stays out of $fillable and inside $hidden, so it
 * can never be mass-assigned from request input nor serialised into a
 * response.
 *
 * SOFT DELETES: the table already had a `deleted_at` DATETIME and the legacy
 * repositories filtered on it by hand in every query. Laravel's SoftDeletes
 * trait is that same behaviour as a framework feature, applied globally
 * instead of per-query — which removes the class of bug where one forgotten
 * `AND deleted_at IS NULL` leaks a deleted account.
 *
 * STAGE 1 ADDED: the three profile/audit relationships the auth flow needs, and
 * nothing else. Still no role-check helpers and no scopes — tenant scoping is a
 * later stage (Policies) and putting a half-version of it here now would have
 * to be unpicked then.
 */
class User extends Authenticatable
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'users';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Note the absence of `password_hash`, `id` and `is_active`: an account's
     * hash, primary key and active flag must never be settable from a request
     * payload.
     */
    protected $fillable = [
        'username',
        'email',
        'full_name',
        'role',
        'avatar_url',
    ];

    protected $hidden = [
        'password_hash',
    ];

    /**
     * `is_active` is TINYINT(1) and comes back from MySQL as int 1/0 (proven
     * in tests/Feature/PdoTypeCastingTest.php). The frontend expects a real
     * boolean, so this cast is load-bearing, not cosmetic.
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /** The column holding the bcrypt hash — legacy name, native mechanism. */
    public function getAuthPassword(): string
    {
        return (string) $this->getAttribute('password_hash');
    }

    /**
     * The instructor profile. hasOne, keyed on teachers.user_id, which is that
     * table's PRIMARY KEY — a 1:1 that Laravel expresses as hasOne because the
     * foreign key lives on the other side.
     *
     * Read by App\Http\Resources\PublicUser to attach `grades`/`subjects` to
     * the /auth/me payload for the `instructor` role. Same source as the
     * teacher presenter, so the two can never disagree.
     */
    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class, 'user_id', 'id');
    }

    /** The student profile. Same 1:1-via-primary-key shape as teacher(). */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class, 'user_id', 'id');
    }

    /** Login audit rows — NOT PHP sessions. See App\Models\LoginSession. */
    public function loginSessions(): HasMany
    {
        return $this->hasMany(LoginSession::class, 'user_id', 'id');
    }

    /**
     * The personal inbox. STAGE 8.
     *
     * ╔══════════════════════════════════════════════════════════════════════╗
     * ║ THIS RELATION **IS** THE AUTHORIZATION FOR THE TWO INBOX ROUTES.     ║
     * ║                                                                      ║
     * ║ Open decision 3.3 settled that mark-read is SCOPE-based, not          ║
     * ║ policy-based, and there is no NotificationPolicy anywhere in the      ║
     * ║ application. The controller reads                                     ║
     * ║                                                                      ║
     * ║     $request->user()->notifications()->visible()->find($id)           ║
     * ║                                                                      ║
     * ║ so the `user_id = <session user>` predicate is compiled into the      ║
     * ║ query by the relation itself. A foreign id cannot come back from it   ║
     * ║ however the request is shaped, and — unlike a policy — an ADMIN gets  ║
     * ║ no special reach into someone else's inbox, because `Gate::before`    ║
     * ║ never enters the picture. A missing row is answered 404, never 403:   ║
     * ║ a guessed uuid must not confirm that a resource exists.               ║
     * ╚══════════════════════════════════════════════════════════════════════╝
     *
     * No default ordering is attached here. Ordering is `latest()` at the one
     * call site, because the visibility scope and the 100-row cap belong to the
     * same statement and reading them together is what makes the contract legible.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'user_id', 'id');
    }
}
