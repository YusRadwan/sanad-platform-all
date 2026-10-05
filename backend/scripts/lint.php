<?php

declare(strict_types=1);

/**
 * `composer lint` — runs `php -l` (syntax check only, no autoload) over every
 * .php file in the project except vendor/, storage/, node_modules/ and the
 * two Composer/PHPUnit caches, mirroring the description already committed
 * in .github/workflows/ci.yml.
 *
 * Deliberately a plain script rather than PHP_CodeSniffer/Pint: this is a
 * fast, dependency-free gate for "does the tree even parse", cheap enough to
 * run on every push. Style enforcement (Pint) is a separate, opt-in concern.
 */

$root = dirname(__DIR__);

$excludedDirs = ['vendor', 'storage', 'node_modules', '.git'];

$hadError = false;
$checked = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file, string $key, RecursiveDirectoryIterator $iterator) use ($excludedDirs): bool {
            if ($file->isDir()) {
                return ! in_array($file->getFilename(), $excludedDirs, true);
            }

            return true;
        }
    )
);

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $checked++;

    $output = [];
    $exitCode = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        $hadError = true;
        echo implode("\n", $output), "\n";
    }
}

if ($checked === 0) {
    fwrite(STDERR, "lint: no .php files found — check the exclude list.\n");
    exit(1);
}

if ($hadError) {
    fwrite(STDERR, "lint: syntax errors found above.\n");
    exit(1);
}

echo "lint: {$checked} files OK\n";
exit(0);
