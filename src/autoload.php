<?php

declare(strict_types=1);

/** Minimal development bootstrap; Composer's PSR-4 autoloader replaces this in releases. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'OPGateway\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
