<?php

declare(strict_types=1);

namespace OPGateway\Security;

use PDO;
use RuntimeException;

/**
 * Server-validated CAPTCHA for password login only.
 *
 * Offline mode is a short-lived, single-use session challenge with a separate
 * per-IP throttle. It is weaker than Google's abuse detection; always retain
 * the existing password login limiter as the authoritative brute-force guard.
 * The Google provider uses the standard reCAPTCHA v2 checkbox and siteverify.
 */
final readonly class LoginCaptcha
{
    private const CHALLENGE_KEY = 'opgateway_login_captcha';
    private const CHALLENGE_TTL = 180;
    private const WINDOW_SECONDS = 900;
    private const IP_LIMIT = 30;

    public function __construct(
        private PdoLoginCaptchaSettings $settings,
        private PDO $pdo,
        private string $limiterKey,
        private string $origin,
        private string $scope = 'account',
    ) {
        if (!in_array($scope, ['account', 'admin'], true)
            || strlen($limiterKey) < 32
            || preg_match('~^https://[a-z0-9.-]+$~D', $origin) !== 1) {
            throw new RuntimeException('CAPTCHA configuration unavailable.');
        }
    }

    /** @return list<string> */
    public function fields(): array
    {
        return $this->settings->summary()['provider'] === 'recaptcha'
            ? ['g-recaptcha-response']
            : ['captcha_id', 'captcha_answer'];
    }

    /** @return array{mode:string,image_url?:string,challenge_id?:string,site_key?:string} */
    public function challenge(): array
    {
        self::assertSession();
        $settings = $this->settings->summary();
        if ($settings['provider'] === 'recaptcha') {
            unset($_SESSION[$this->sessionKey()]);
            if ($this->settings->recaptchaCredentials() === null) {
                throw new RuntimeException('reCAPTCHA is not configured.');
            }
            return ['mode' => 'recaptcha', 'site_key' => $settings['site_key']];
        }
        $answer = OfflineCaptchaImage::randomAnswer();
        $png = OfflineCaptchaImage::render($answer);
        $id = bin2hex(random_bytes(16));
        $_SESSION[$this->sessionKey()] = [
            'id' => $id,
            'answer_hash' => hash_hmac(
                'sha256', $answer, $this->limiterKey . $id,
            ),
            'image' => $png,
            'issued_at' => time(),
        ];
        return [
            'mode' => 'offline',
            'challenge_id' => $id,
            'image_url' => '/' . $this->scope . '/captcha/image',
        ];
    }

    /** Read-only, session-bound binary image, never the solution. */
    public function image(): ?string
    {
        self::assertSession();
        if ($this->settings->summary()['provider'] !== 'offline') {
            return null;
        }
        $pending = $_SESSION[$this->sessionKey()] ?? null;
        return is_array($pending)
            && is_string($pending['image'] ?? null)
            && is_int($pending['issued_at'] ?? null)
            && $pending['issued_at'] <= time()
            && time() - $pending['issued_at'] <= self::CHALLENGE_TTL
            ? $pending['image'] : null;
    }

    /** @param array<string,string> $fields */
    public function verify(array $fields, string $sourceIp): bool
    {
        self::assertSession();
        // Consume a throttle slot before checking a challenge. No bypass
        // exists for incorrect tokens or fresh browser sessions from one IP.
        if (!$this->allowIp($sourceIp)) {
            unset($_SESSION[$this->sessionKey()]);
            return false;
        }

        $mode = $this->settings->summary()['provider'];
        if ($mode === 'offline') {
            $pending = $_SESSION[$this->sessionKey()] ?? null;
            unset($_SESSION[$this->sessionKey()]);
            if (!is_array($pending)
                || !is_string($pending['id'] ?? null)
                || !is_string($pending['answer_hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $pending['answer_hash']) !== 1
                || !is_int($pending['issued_at'] ?? null)
                || $pending['issued_at'] > time()
                || time() - $pending['issued_at'] > self::CHALLENGE_TTL
                || !is_string($fields['captcha_id'] ?? null)
                || !hash_equals($pending['id'], $fields['captcha_id'])
                || preg_match('/^[A-Z2-9]{6}$/D',
                    strtoupper(trim($fields['captcha_answer'] ?? '')),
                ) !== 1) {
                return false;
            }
            return hash_equals(
                $pending['answer_hash'],
                hash_hmac('sha256', strtoupper(trim($fields['captcha_answer'])),
                    $this->limiterKey . $pending['id']),
            );
        }

        unset($_SESSION[$this->sessionKey()]);
        $credentials = $this->settings->recaptchaCredentials();
        $token = $fields['g-recaptcha-response'] ?? '';
        if ($credentials === null || $token === '' || strlen($token) > 4096
            || !extension_loaded('curl')) {
            return false;
        }
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        if ($ch === false) {
            return false;
        }
        $response = '';
        try {
            if (!curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'secret' => $credentials['secret'], 'response' => $token,
                ], '', '&', PHP_QUERY_RFC3986),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk)
                    use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 4096) {
                        return 0;
                    }
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ])) {
                return false;
            }
            $ok = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($ch);
        }
        if ($ok === false || $status !== 200 || $response === '') {
            return false;
        }
        try {
            $result = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        $hostname = parse_url($this->origin, PHP_URL_HOST);
        return is_array($result)
            && ($result['success'] ?? null) === true
            && is_string($hostname)
            && ($result['hostname'] ?? null) === $hostname;
    }

    private function allowIp(string $sourceIp): bool
    {
        $packed = @inet_pton($sourceIp);
        if ($packed === false || !in_array(strlen($packed), [4, 16], true)) {
            return false;
        }
        $window = intdiv(time(), self::WINDOW_SECONDS);
        $hash = hash_hmac(
            'sha256', 'captcha:login:ip:' . bin2hex($packed),
            $this->limiterKey, true,
        );
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO login_rate_limits (bucket_hash, window_number, attempts)
VALUES (?, ?, 1)
ON DUPLICATE KEY UPDATE attempts = attempts + 1
SQL);
        $stmt->bindValue(1, $hash, PDO::PARAM_LOB);
        $stmt->bindValue(2, $window, PDO::PARAM_INT);
        $stmt->execute();
        $read = $this->pdo->prepare(<<<'SQL'
SELECT attempts FROM login_rate_limits
WHERE bucket_hash = ? AND window_number = ?
SQL);
        $read->bindValue(1, $hash, PDO::PARAM_LOB);
        $read->bindValue(2, $window, PDO::PARAM_INT);
        $read->execute();
        $count = $read->fetchColumn();
        return $count !== false && (int) $count <= self::IP_LIMIT;
    }

    private function sessionKey(): string
    {
        return self::CHALLENGE_KEY . '_' . $this->scope;
    }

    private static function assertSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('CAPTCHA session unavailable.');
        }
    }
}
