<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    */

    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Only MySQL is declared. The legacy backend/ ran on MySQL 8.4 and this
    | schema leans on features that are MySQL-specific in practice — enforced
    | CHECK constraints (8.0.16+), native JSON columns, ENUM — so offering a
    | sqlite/pgsql connection here would only invite a test suite that passes
    | against a database the application will never actually run on.
    |
    */

    'connections' => [

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'sanad_platform_v2'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',

            /*
             * PDO options. VERIFIED EMPIRICALLY, not assumed — see
             * tests/Manual/pdo_type_probe.php and tests/Feature/PdoTypeCastingTest.php
             * for the runnable proof and the recorded output.
             *
             * backend/app/Core/Database/Connection.php sets
             * ATTR_EMULATE_PREPARES => false. Laravel's MySQL connector
             * (Illuminate\Database\Connectors\Connector::$options) ALREADY
             * defaults to exactly that, plus ATTR_STRINGIFY_FETCHES => false.
             * The probe confirms both connections therefore return identical
             * native PHP types:
             *
             *   TINYINT(1)      -> int (1 / 0)      — never "1"/"0"
             *   TINYINT UNSIGNED-> int (11)         — never "11"
             *   INT             -> int
             *   DECIMAL(10,2)   -> string ("199.50")  <-- NOTE
             *
             * DECIMAL comes back as a STRING on both connections. That is the
             * mysqlnd driver's deliberate behaviour (a DECIMAL cannot survive a
             * float round-trip without precision loss), and it is identical in
             * backend/ today — which is exactly why backend/'s service layer
             * casts money fields explicitly before they reach the wire. The
             * REST of the type shape is preserved for free.
             *
             * These three are therefore restated EXPLICITLY rather than left
             * implicit. They are load-bearing for the frontend contract
             * (frontend/src/types/curriculum.ts wants `phone_digits: number`
             * and `is_active: boolean`), and pinning them here means a future
             * framework default flip cannot silently change the JSON shape the
             * fixed frontend parses.
             */
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ], static fn ($v) => $v !== null) : [],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Unused today (cache and sessions are file-driven, matching backend/'s
    | flat-file rate-limit and session storage). Kept declared so a later
    | stage can switch a driver over without editing this file's structure.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'sanad'), '_').'_database_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
