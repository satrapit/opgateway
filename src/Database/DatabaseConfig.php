<?php

declare(strict_types=1);

namespace OPGateway\Database;

use InvalidArgumentException;

/**
 * Immutable, validated MySQL connection settings.
 *
 * Credentials come from private configuration; do not serialize to logs.
 */
final readonly class DatabaseConfig
{
    private function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        private string $password,
    ) {
    }

    /**
     * @param array<string, string> $environment
     * @throws InvalidArgumentException If a required setting is invalid.
     */
    public static function fromEnvironment(array $environment): self
    {
        $host = trim($environment['DB_HOST'] ?? '');
        $database = trim($environment['DB_NAME'] ?? '');
        $username = trim($environment['DB_USER'] ?? '');
        $port = $environment['DB_PORT'] ?? '3306';

        if ($host === '' || $database === '' || $username === '') {
            throw new InvalidArgumentException('Database connection settings are incomplete.');
        }
        if (!preg_match('/^[a-zA-Z0-9_.:-]+$/D', $host) || strlen($host) > 253) {
            throw new InvalidArgumentException('Invalid database hostname.');
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $database) || strlen($database) > 64) {
            throw new InvalidArgumentException('Invalid database name.');
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $username) || strlen($username) > 64) {
            throw new InvalidArgumentException('Invalid database username.');
        }
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new InvalidArgumentException('Invalid database port.');
        }

        return new self($host, (int) $port, $database, $username, $environment['DB_PASSWORD'] ?? '');
    }

    /** Build a safe DSN without credentials or arbitrary PDO parameters. */
    public function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->database,
        );
    }

    /** @internal Pass to the PDO driver only; never log or display. */
    public function passwordForConnection(): string
    {
        return $this->password;
    }
}
