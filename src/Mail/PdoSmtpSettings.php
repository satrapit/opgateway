<?php

declare(strict_types=1);

namespace OPGateway\Mail;

use OPGateway\Security\SealedSecret;
use PDO;
use InvalidArgumentException;

/**
 * The sole SMTP account is globally managed, not tenant- or user-editable.
 * UI reads never return its password; it is GCM-encrypted in MySQL.
 */
final readonly class PdoSmtpSettings
{
    public function __construct(private PDO $pdo, private SealedSecret $secrets)
    {
    }

    /** @return array{host:string,port:int,encryption:string,username:string,from_email:string,from_name:string,password_set:bool} */
    public function summary(): array
    {
        $row = $this->row();
        return [
            'host' => (string) ($row['host'] ?? ''),
            'port' => (int) ($row['port'] ?? 587),
            'encryption' => (string) ($row['encryption'] ?? 'starttls'),
            'username' => (string) ($row['username'] ?? ''),
            'from_email' => (string) ($row['from_email'] ?? ''),
            'from_name' => (string) ($row['from_name'] ?? ''),
            'password_set' => is_array($row),
        ];
    }

    public function configured(): bool
    {
        return $this->row() !== null;
    }

    public function connection(): ?SmtpConfiguration
    {
        $row = $this->row();
        if ($row === null) {
            return null;
        }
        return new SmtpConfiguration(
            (string) $row['host'],
            (int) $row['port'],
            (string) $row['encryption'],
            (string) $row['username'],
            $this->secrets->decrypt((string) $row['password_cipher'], 'smtp:1'),
            (string) $row['from_email'],
            (string) $row['from_name'],
        );
    }

    /**
     * The caller MUST verify a freshly authorized administrator and CSRF.
     * Blank password means retain the old encrypted credential, never erase.
     */
    public function save(
        string $host, int $port, string $encryption, string $username,
        string $password, string $fromEmail, string $fromName,
    ): void {
        $existing = $this->row();
        if ($password === '' && $existing === null) {
            throw new InvalidArgumentException('The first SMTP setup requires a password.');
        }
        if ($password === '' && is_array($existing)) {
            $password = $this->secrets->decrypt((string) $existing['password_cipher'], 'smtp:1');
        }
        $settings = new SmtpConfiguration(
            strtolower(trim($host)), $port, $encryption,
            trim($username), $password, strtolower(trim($fromEmail)), trim($fromName),
        );
        $cipher = $this->secrets->encrypt($settings->password, 'smtp:1');
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO smtp_settings
 (id, host, port, encryption, username, password_cipher,
  from_email, from_name, updated_at)
VALUES (1, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE host=VALUES(host), port=VALUES(port),
 encryption=VALUES(encryption), username=VALUES(username),
 password_cipher=VALUES(password_cipher), from_email=VALUES(from_email),
 from_name=VALUES(from_name), updated_at=UTC_TIMESTAMP(6)
SQL);
        $stmt->execute([
            $settings->host, $settings->port, $settings->encryption,
            $settings->username, $cipher, $settings->fromEmail, $settings->fromName,
        ]);
    }

    /** @return array<string,mixed>|null */
    private function row(): ?array
    {
        $stmt = $this->pdo->query('SELECT * FROM smtp_settings WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
