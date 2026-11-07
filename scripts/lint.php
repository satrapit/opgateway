<?php

declare(strict_types=1);

$directories = [__DIR__ . '/../src', __DIR__ . '/../public', __DIR__ . '/../tests'];
$failed = false;
foreach ($directories as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            $failed = true;
            fwrite(STDERR, implode("\n", $output) . "\n");
        }
        $output = [];
    }
}
exit($failed ? 1 : 0);
