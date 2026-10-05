<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Stage 0 seed set.
 *
 * Everything seeded here is REFERENCE DATA the product cannot function
 * without (option catalogs, a bootstrap admin). There is deliberately no
 * demo/fixture data: this seed set is safe to run in production, and a
 * `migrate:fresh --seed` in development produces exactly the state a fresh
 * deployment would have.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            CatalogSeeder::class,
        ]);
    }
}
