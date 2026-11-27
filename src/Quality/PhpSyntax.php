<?php

declare(strict_types=1);

namespace OPGateway\Quality;

use FilesystemIterator;
use ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Parse application PHP sources without executing them or spawning commands.
 * Excludes vendor/node_modules and other dependency directories by design.
 */
final class PhpSyntax
{
    public static function scanRoot(string $root): int
    {
        $count = 0;
        foreach (['src', 'public', 'tests', 'tests-integration', 'scripts'] as $name) {
            $directory = $root . '/' . $name;
            if (!is_dir($directory) || is_link($directory)) {
                throw new RuntimeException('PHP source directory unavailable: ' . $name);
            }
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php'
                    || is_link($file->getPathname())) {
                    continue;
                }
                $path = $file->getPathname();
                $source = file_get_contents($path);
                if (!is_string($source)) {
                    throw new RuntimeException('PHP source unreadable.');
                }
                try {
                    token_get_all($source, TOKEN_PARSE);
                } catch (ParseError $exception) {
                    throw new RuntimeException('Invalid PHP syntax: ' . $name
                        . '/' . $file->getFilename(), 0, $exception);
                }
                $count++;
            }
        }
        if ($count === 0) {
            throw new RuntimeException('No PHP source files found.');
        }
        return $count;
    }
}
