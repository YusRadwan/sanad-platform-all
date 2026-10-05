<?php

declare(strict_types=1);

/**
 * Creates the backend-v2 database.
 *
 * There is no `mysql` CLI on the target machines, so the database itself
 * (the one thing `php artisan migrate` cannot create for you) is provisioned
 * with a bare PDO connection to the server — the same approach backend/'s
 * scripts have always used.
 *
 * Reads DB_* from backend-v2/.env so it can never be pointed at another
 * database by accident, and refuses outright to touch the legacy
 * `sanad_platform_test` database that backend/ owns.
 *
 * Usage: php database/create_database.php
 */

$root = dirname(__DIR__);

/** Minimal .env reader — this script runs before the framework is booted. */
$env = [];
foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$name = $env['DB_DATABASE'] ?? '';
$user = $env['DB_USERNAME'] ?? 'root';
$pass = $env['DB_PASSWORD'] ?? '';

$forbidden = ['sanad_platform_test', 'sanad_platform'];
if (in_array($name, $forbidden, true)) {
    fwrite(STDERR, "REFUSING: '{$name}' belongs to the legacy backend/ application.\n");
    exit(1);
}

if ($name === '') {
    fwrite(STDERR, "REFUSING: DB_DATABASE is empty in .env\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s', $host, $port),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec(sprintf(
    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    str_replace('`', '', $name)
));

echo "MySQL: " . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
echo "Database `{$name}` is ready (utf8mb4 / utf8mb4_unicode_ci).\n";
