<?php

declare(strict_types=1);

/**
 * LOCAL DEVELOPMENT ONLY: reset an eligible test account password.
 *
 * Password input comes from STDIN, never command-line arguments. The
 * destination must be the explicitly expected local HTTPS application with
 * a loopback MySQL host. No other account fields or roles are changed.
 *
 * Example (bash/zsh, without storing password in command history):
 *   printf 'New local password: '; read -rs pw; printf '\n'
 *   printf '%s\n' "$pw" | php scripts/local-set-account-password.php test@example.test
 *   unset pw
 */
use OPGateway\Config\Environment;
use OPGateway\Database\PdoConnectionFactory;
use OPGateway\Identity\PasswordHasher;

if (PHP_SAPI !== 'cli' || $argc !== 2) {
    fwrite(STDERR, "Usage: php scripts/local-set-account-password.php EMAIL\n");
    exit(2);
}

require dirname(__DIR__) . '/src/autoload.php';

try {
    $config = Environment::load(dirname(__DIR__) . '/.env');
    if (($config['APP_ENV'] ?? '') !== 'local'
        || ($config['APP_URL'] ?? '') !== 'https://opgateway.test'
        || !in_array(($config['DB_HOST'] ?? ''), ['127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Local configuration guard failed; no account modified.');
    }

    $email = strtolower(trim((string) $argv[1]));
    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Invalid account email.');
    }

    $line = fgets(STDIN, 1026);
    if (!is_string($line)) {
        throw new RuntimeException('Password input missing.');
    }
    $password = rtrim($line, "\r\n");
    $length = strlen($password);
    if ($length < 12 || $length > 1024 || str_contains($password, "\0")) {
        throw new InvalidArgumentException('Password must be 12–1024 bytes with no NUL.');
    }

    $hash = (new PasswordHasher())->hash($password);
    unset($password, $line);
    $pdo = PdoConnectionFactory::open($config);
    $statement = $pdo->prepare(<<<'SQL'
UPDATE users
SET password_hash = ?, updated_at = UTC_TIMESTAMP(6)
WHERE email = ?
  AND status = 'active'
  AND email_verified_at IS NOT NULL
  AND approved_at IS NOT NULL
SQL);
    $statement->execute([$hash, $email]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('No eligible local account was updated.');
    }

    fwrite(STDOUT, "Updated one eligible account in the local database.\n");
} catch (Throwable $exception) {
    // Do not echo exception messages or query parameters on CLI failures.
    fwrite(STDERR, "Account password reset denied or failed.\n");
    exit(1);
}
