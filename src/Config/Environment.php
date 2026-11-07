<?php

declare(strict_types=1);

namespace OPGateway\Config;

use RuntimeException;

/**
 * Loads simple KEY=VALUE configuration without interpolation or shell execution.
 * The foundation loader deliberately refuses duplicate entries and unsafe syntax.
 * It never reads process environment as a fallback, to avoid ambiguous precedence.
 */
final class Environment
{
    /** @return array<string, string> */
    public static function load(string $filename): array
    {
        if (!is_file($filename) || !is_readable($filename)) {
            throw new RuntimeException('Configuration file is missing.');
        }
        $result = [];
        foreach (file($filename, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/D', $line, $matches)) {
                throw new RuntimeException('Invalid configuration syntax.');
            }
            $name = $matches[1];
            if (array_key_exists($name, $result)) {
                throw new RuntimeException('Duplicate configuration key.');
            }
            $value = trim($matches[2]);
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            }
            $result[$name] = $value;
        }
        return $result;
    }
}
