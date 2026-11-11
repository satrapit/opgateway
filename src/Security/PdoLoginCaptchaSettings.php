<?php

declare(strict_types=1);

namespace OPGateway\Security;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Authentication CAPTCHA configuration stored in the existing settings table.
 * Never expose the encrypted reCAPTCHA secret to a browser or a log.
 */
final readonly class PdoLoginCaptchaSettings
{
    public function __construct(private PDO $pdo, private SealedSecret $vault)
    {
    }

    /** @return array{provider:string,site_key:string,secret_set:bool} */
    public function summary(): array
    {
        $rows = $this->rows();
        $provider = $rows['login.captcha_provider'] ?? 'offline';
        if (!in_array($provider, ['offline', 'recaptcha'], true)) {
            throw new RuntimeException('Unsupported CAPTCHA configuration.');
        }
        return [
            'provider' => $provider,
            'site_key' => $rows['login.recaptcha_site_key'] ?? '',
            'secret_set' => isset($rows['login.recaptcha_secret']),
        ];
    }

    /** @return array{site_key:string,secret:string}|null */
    public function recaptchaCredentials(): ?array
    {
        $rows = $this->rows();
        if (($rows['login.captcha_provider'] ?? 'offline') !== 'recaptcha') {
            return null;
        }
        $siteKey = $rows['login.recaptcha_site_key'] ?? '';
        if (!self::validKey($siteKey) || !isset($rows['login.recaptcha_secret'])) {
            return null;
        }
        $secret = $this->vault->decrypt(
            $rows['login.recaptcha_secret'], 'captcha:recaptcha',
        );
        return self::validKey($secret) ? ['site_key' => $siteKey, 'secret' => $secret] : null;
    }

    public function save(string $provider, string $siteKey, string $secret): void
    {
        if (!in_array($provider, ['offline', 'recaptcha'], true)
            || strlen($siteKey) > 200 || strlen($secret) > 200) {
            throw new InvalidArgumentException('Invalid CAPTCHA settings.');
        }
        $existing = $this->rows();
        $siteKey = trim($siteKey);
        $storedSiteKey = $siteKey !== '' ? $siteKey
            : ($existing['login.recaptcha_site_key'] ?? '');
        if ($provider === 'recaptcha' && $secret === ''
            && $siteKey !== ''
            && $siteKey !== ($existing['login.recaptcha_site_key'] ?? '')) {
            throw new InvalidArgumentException(
                'Changing a reCAPTCHA site key requires its matching secret.',
            );
        }
        $storedSecret = $secret === ''
            ? ($existing['login.recaptcha_secret'] ?? null)
            : $this->vault->encrypt($secret, 'captcha:recaptcha');
        if ($provider === 'recaptcha'
            && (!self::validKey($storedSiteKey) || $storedSecret === null
                || ($secret !== '' && !self::validKey($secret)))) {
            throw new InvalidArgumentException('Google reCAPTCHA requires both keys.');
        }
        if ($siteKey !== '' && !self::validKey($siteKey)) {
            throw new InvalidArgumentException('Invalid reCAPTCHA site key.');
        }
        if ($secret !== '' && !self::validKey($secret)) {
            throw new InvalidArgumentException('Invalid reCAPTCHA secret.');
        }

        $this->upsert('login.captcha_provider', $provider);
        if ($storedSiteKey !== '') {
            $this->upsert('login.recaptcha_site_key', $storedSiteKey);
        }
        if ($storedSecret !== null) {
            $this->upsert('login.recaptcha_secret', $storedSecret);
        }
    }

    private static function validKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{20,200}$/D', $key) === 1;
    }

    private function upsert(string $name, string $value): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO settings (setting_key, setting_value, updated_at)
VALUES (?, ?, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
updated_at = UTC_TIMESTAMP(6)
SQL);
        $stmt->execute([$name, $value]);
    }

    /** @return array<string,string> */
    private function rows(): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT setting_key, setting_value FROM settings
WHERE setting_key IN (
  'login.captcha_provider', 'login.recaptcha_site_key', 'login.recaptcha_secret'
)
SQL);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        return is_array($rows) ? $rows : [];
    }
}
