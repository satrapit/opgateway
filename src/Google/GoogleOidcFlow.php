<?php

declare(strict_types=1);

namespace OPGateway\Google;

use RuntimeException;

/** One pending, five-minute Google authorization per PHP browser session. */
final class GoogleOidcFlow
{
    private const SESSION_KEY = 'opgateway_google_oidc';
    private const MAX_AGE = 300;

    /**
     * @return array{url:string,state:string}
     */
    public static function begin(
        string $clientId, string $origin, string $mode, ?int $userId,
        ?string $action = null,
    ): array
    {
        self::assertSession();
        if (!PdoGoogleOAuthSettings::validClientId($clientId)
            || preg_match('~^https://[a-z0-9.-]+$~D', $origin) !== 1
            || !in_array($mode, ['login', 'link', 'verify'], true)
            || (in_array($mode, ['link', 'verify'], true)
                && ($userId === null || $userId < 1))
            || ($mode === 'verify'
                && !in_array($action, ['api_key_issue', 'provider_credentials'], true))
            || ($mode !== 'verify' && $action !== null)) {
            throw new RuntimeException('Invalid Google authorization request.');
        }

        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = self::base64Url(random_bytes(48));
        $transactionId = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = [
            'mode' => $mode, 'user_id' => $mode === 'login' ? null : $userId,
            'action' => $action, 'transaction_id' => $transactionId,
            'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier,
            'created_at' => time(),
        ];
        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $origin . '/account/google/callback',
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ];
        if ($mode === 'verify') {
            // Google only returns auth_time when it is explicitly requested.
            // Step-up must prove recent provider authentication, not merely a
            // fresh authorization code issued from an old Google SSO session.
            $params['claims'] = json_encode([
                'id_token' => ['auth_time' => ['essential' => true]],
            ], JSON_THROW_ON_ERROR);
        }
        return [
            'url' => 'https://accounts.google.com/o/oauth2/v2/auth?'
                . http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            'state' => $state,
        ];
    }

    /** @return array{mode:string,user_id:?int,action:?string,transaction_id:string,nonce:string,verifier:string}|null */
    public static function take(string $state): ?array
    {
        self::assertSession();
        $pending = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        if (!is_array($pending) || preg_match('/^[0-9a-f]{64}$/D', $state) !== 1
            || !is_string($pending['state'] ?? null)
            || !hash_equals($pending['state'], $state)
            || !is_int($pending['created_at'] ?? null)
            || $pending['created_at'] > time()
            || time() - $pending['created_at'] > self::MAX_AGE
            || !in_array($pending['mode'] ?? null, ['login', 'link', 'verify'], true)
            || !is_string($pending['transaction_id'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $pending['transaction_id']) !== 1
            || !is_string($pending['nonce'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $pending['nonce']) !== 1
            || !is_string($pending['verifier'] ?? null)
            || preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $pending['verifier']) !== 1) {
            return null;
        }
        $userId = $pending['user_id'] ?? null;
        if (in_array($pending['mode'], ['link', 'verify'], true)
            && (!is_int($userId) || $userId < 1)) {
            return null;
        }
        $action = $pending['action'] ?? null;
        if (($pending['mode'] === 'verify'
                && !in_array($action, ['api_key_issue', 'provider_credentials'], true))
            || ($pending['mode'] !== 'verify' && $action !== null)) {
            return null;
        }
        return [
            'mode' => $pending['mode'], 'user_id' => $userId,
            'action' => $action, 'transaction_id' => $pending['transaction_id'],
            'nonce' => $pending['nonce'], 'verifier' => $pending['verifier'],
        ];
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function assertSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Browser session is unavailable.');
        }
    }
}
