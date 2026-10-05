<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Seeds the bootstrap admin account — the conceptual equivalent of
 * backend/database/seeders/seed_admin.php, moved onto Laravel's standard
 * seeder convention.
 *
 * TWO DELIBERATE DIFFERENCES FROM THE LEGACY SCRIPT
 * ------------------------------------------------------------------
 * 1. THE PASSWORD IS NEVER HARD-CODED AND HAS NO DEFAULT. The seeder ABORTS
 *    if SEED_ADMIN_PASSWORD is unset. A default credential that ships in a
 *    repository is a real vulnerability, and "we'll change it after deploy"
 *    is exactly the promise that does not survive contact with a rushed
 *    launch. Failing loudly is the safe behaviour.
 *
 * 2. IT IS IDEMPOTENT (updateOrCreate on the unique username). `migrate:fresh
 *    --seed` and a re-run against an existing database both end in the same
 *    state instead of a duplicate-key crash.
 *
 * Hash::make() uses Laravel's configured bcrypt driver (BCRYPT_ROUNDS=12),
 * producing the same $2y$ hash format PHP's password_hash() produced in
 * backend/ — so a hash written by either codebase verifies under the other.
 * That matters if the two backends ever run side by side during cutover.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) env('SEED_ADMIN_PASSWORD', '');

        if ($password === '') {
            throw new RuntimeException(
                'SEED_ADMIN_PASSWORD is not set. Refusing to seed an admin account '
                . 'with a default password. Set it in .env and re-run.'
            );
        }

        $username = (string) env('SEED_ADMIN_USERNAME', 'admin');

        $user = User::withTrashed()->firstWhere('username', $username);

        if ($user === null) {
            $user = new User();
            $user->username = $username;
        }

        $user->email = (string) env('SEED_ADMIN_EMAIL', 'admin@sanad.local');
        $user->full_name = (string) env('SEED_ADMIN_FULL_NAME', 'مدير النظام');
        $user->role = UserRole::Admin;
        $user->is_active = true;
        $user->password_hash = Hash::make($password);
        $user->deleted_at = null;
        $user->save();

        $this->command?->info("Admin user '{$username}' seeded.");
    }
}
