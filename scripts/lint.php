<?php

declare(strict_types=1);

/**
 * PHP-native syntax check. Never starts shell commands or requires Composer.
 * The same parser is used by the local administrator QA dashboard.
 */
require dirname(__DIR__) . '/src/autoload.php';

try {
    $count = \OPGateway\Quality\PhpSyntax::scanRoot(dirname(__DIR__));
    echo "PASS: {$count} PHP files parsed without execution.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    exit(1);
}
