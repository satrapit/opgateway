<?php

declare(strict_types=1);

namespace OPGateway\Mail;

use OPGateway\Security\SealedSecret;
use PDO;
use InvalidArgumentException;

/**
 * SMTP validation and encryption over the shared key/value settings table.
 * Only an authenticated administrator may call save() through the controller.
 * Neither views nor diagnostic logs receive the SMTP password.
 */
final readonly class PdoSmtpSettings
{
    private const KEYS = [
        'smtp.host', 'smtp.port', 'smtp.encryption', 'smtp.username',
        'smtp.password', 'smtp.from_email', 'smtp.from_name',
    ];

    public function __construct(private PDO $pdo, private SealedSecret $secrets)
    {
    }

    /** @return array{host:string,port:int,encryption:string,username:string,from_email:string,from_name:string,password_set:bool} */
    public function summary(): array
    {
        $row = $this->row();
        return [
            'host' => $row['smtp.host'] ?? '',
            'port' => isset($row['smtp.port']) ? (int) $row['smtp.port'] : 587,
            'encryption' => $row['smtp.encryption'] ?? 'starttls',
            'username' => $row['smtp.username'] ?? '',
            'from_email' => $row['smtp.from_email'] ?? '',
            'from_name' => $row['smtp.from_name'] ?? '',
            'password_set' => $row !== null,
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
            $row['smtp.host'],
            (int) $row['smtp.port'],
            $row['smtp.encryption'],
            $row['smtp.username'],
            $this->secrets->decrypt($row['smtp.password'], 'smtp:1'),
            $row['smtp.from_email'],
            $row['smtp.from_name'],
        );
    }

    /**
     * Administrator role, current password and CSRF are verified by the caller.
     * A blank password preserves the previous value, never erases it.
     * A single SQL upsert keeps all seven settings consistent.
     */
    public function save(
        string $host, int $port, string $encryption, string $username,
        string $password, string $fromEmail, string $fromName,
    ): void {
        $existing = $this->row();
        if ($password === '' && $existing === null) {
            throw new InvalidArgumentException('The first SMTP setup requires a password.');
        }
        if ($password === '' && $existing !== null) {
            $password = $this->secrets->decrypt($existing['smtp.password'], 'smtp:1');
        }
        $settings = new SmtpConfiguration(
            strtolower(trim($host)), $port, $encryption,
            trim($username), $password, strtolower(trim($fromEmail)), trim($fromName),
        );
        $cipher = $this->secrets->encrypt($settings->password, 'smtp:1');

        $values = [
            'smtp.host' => $settings->host,
            'smtp.port' => (string) $settings->port,
            'smtp.encryption' => $settings->encryption,
            'smtp.username' => $settings->username,
            'smtp.password' => $cipher,
            'smtp.from_email' => $settings->fromEmail,
            'smtp.from_name' => $settings->fromName,
        ];
        // All keys originate in this class, never from browser input.
        $placeholders = implode(', ', array_fill(0, count($values), '(?, ?, UTC_TIMESTAMP(6))'));
        $query = 'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES '
            . $placeholders
            . ' ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), '
            . 'updated_at = UTC_TIMESTAMP(6)';
        $args = [];
        foreach ($values as $key => $value) {
            $args[] = $key;
            $args[] = $value;
        }
        $this->pdo->prepare($query)->execute($args);
    }

    /** @return array<string,string>|null */
    private function row(): ?array
    {
        $placeholders = implode(', ', array_fill(0, count(self::KEYS), '?'));
        $stmt = $this->pdo->prepare('SELECT setting_key, setting_value FROM settings '
            . 'WHERE setting_key IN (' . $placeholders . ')');
        $stmt->execute(self::KEYS);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!is_array($rows) || count($rows) !== count(self::KEYS)) {
            return null;
        }
        return $rows;
    }
}
