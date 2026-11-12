<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\Migrations\CreateGoogleIdentities;
use OPGateway\Database\Migrations\MigrationRunner;
use OPGateway\Google\GoogleOidcFlow;
use OPGateway\Google\GoogleOidcIdTokenVerifier;
use OPGateway\Google\PdoGoogleOAuthSettings;
use OPGateway\Http\AccountViews;
use OPGateway\Http\GoogleOidcBrowserController;
use OPGateway\Identity\BrowserSession;
use OPGateway\Security\SealedSecret;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class GoogleOidcPolicyTest extends TestCase
{
    private const CLIENT_ID = '123456789012-abcdefghijk.apps.googleusercontent.com';
    private const CSRF = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    public function testMigrationSevenHasIndependentUniqueGoogleSubject(): void
    {
        $migration = new CreateGoogleIdentities();
        $script = file_get_contents(dirname(__DIR__) . '/database/sql/upgrade_202611110001.sql');
        self::assertIsString($script);
        self::assertStringContainsString(MigrationRunner::checksum($migration), $script);
        foreach ($migration->statements() as $sql) {
            self::assertStringContainsString($sql . ';', $script);
        }
        self::assertStringContainsString('PRIMARY KEY (user_id)', $script);
        self::assertStringContainsString('UNIQUE KEY uq_google_identities_sub (google_sub)', $script);
        self::assertStringContainsString('COLLATE ascii_bin', $script);
    }

    public function testExistingPasswordSignInRemainsAvailable(): void
    {
        $disabled = AccountViews::login(self::CSRF);
        self::assertStringContainsString('name="password"', $disabled);
        self::assertStringNotContainsString('href="/account/google/start"', $disabled);

        $enabled = AccountViews::login(self::CSRF, false, true, true);
        self::assertStringContainsString('name="password"', $enabled);
        self::assertStringContainsString('href="/account/google/start"', $enabled);

        $settings = AccountViews::google(self::CSRF, 'user@example.test', true, null);
        self::assertStringContainsString('action="/account/google/link"', $settings);
        self::assertStringContainsString('name="current_password"', $settings);
        self::assertStringNotContainsString('action="/account/google/unlink"', $settings);

        $linked = AccountViews::google(self::CSRF, 'user@example.test', true, 'user@example.test');
        self::assertStringContainsString('action="/account/google/unlink"', $linked);
        self::assertStringNotContainsString('action="/account/google/link"', $linked);
        self::assertTrue(GoogleOidcBrowserController::route('/account/google/callback'));
        self::assertTrue(GoogleOidcBrowserController::route('/account/google/continue'));
        self::assertFalse(GoogleOidcBrowserController::route('/account/google/unlisted'));
    }

    public function testGoogleOAuthClientIdAndSecretContextAreValidated(): void
    {
        self::assertTrue(PdoGoogleOAuthSettings::validClientId(self::CLIENT_ID));
        self::assertFalse(PdoGoogleOAuthSettings::validClientId('http://127.0.0.1'));
        self::assertFalse(PdoGoogleOAuthSettings::validClientId('not-a-real-client'));
        $vault = new SealedSecret(str_repeat('a', 64));
        $cipher = $vault->encrypt('example-oauth-secret', 'google:oauth');
        self::assertStringStartsWith('v1:', $cipher);
        self::assertNotSame('example-oauth-secret', $cipher);
        self::assertSame('example-oauth-secret', $vault->decrypt($cipher, 'google:oauth'));
        $this->expectException(\RuntimeException::class);
        $vault->decrypt($cipher, 'smtp:1');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOAuthFlowUsesPkceAndOneTimeState(): void
    {
        $session = new BrowserSession();
        $session->start(true);
        $cookie = session_get_cookie_params();
        self::assertSame('Lax', $cookie['samesite']);
        self::assertTrue($cookie['secure']);
        self::assertTrue($cookie['httponly']);
        $flow = GoogleOidcFlow::begin(
            self::CLIENT_ID, 'https://opgateway.test', 'login', null,
        );
        self::assertStringStartsWith(
            'https://accounts.google.com/o/oauth2/v2/auth?', $flow['url'],
        );
        $query = parse_url($flow['url'], PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $params);
        self::assertSame('code', $params['response_type']);
        self::assertSame('S256', $params['code_challenge_method']);
        self::assertSame('openid email', $params['scope']);
        self::assertSame('https://opgateway.test/account/google/callback',
            $params['redirect_uri']);
        self::assertSame($flow['state'], $params['state']);
        $pending = GoogleOidcFlow::take($flow['state']);
        self::assertIsArray($pending);
        self::assertSame('login', $pending['mode']);
        self::assertNull($pending['user_id']);
        self::assertSame($params['nonce'], $pending['nonce']);
        self::assertSame($params['code_challenge'], rtrim(strtr(
            base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_',
        ), '='));
        self::assertNull(GoogleOidcFlow::take($flow['state']));

        $verify = GoogleOidcFlow::begin(
            self::CLIENT_ID, 'https://opgateway.test', 'verify', 27,
            'api_key_issue',
        );
        $verifyQuery = parse_url($verify['url'], PHP_URL_QUERY);
        self::assertIsString($verifyQuery);
        parse_str($verifyQuery, $verifyParams);
        self::assertSame(
            ['id_token' => ['auth_time' => ['essential' => true]]],
            json_decode((string) ($verifyParams['claims'] ?? ''), true),
        );
        self::assertIsArray(GoogleOidcFlow::take($verify['state']));

        $link = GoogleOidcFlow::begin(
            self::CLIENT_ID, 'https://opgateway.test', 'link', 27,
        );
        self::assertNull(GoogleOidcFlow::take(str_repeat('0', 64)));
        // Incorrect state consumes the flow; it cannot be replayed.
        self::assertNull(GoogleOidcFlow::take($link['state']));

        $session->grantGoogleLink(27);
        self::assertFalse($session->consumeGoogleLink(28));
        $session->grantGoogleLink(27);
        self::assertTrue($session->consumeGoogleLink(27));
        self::assertFalse($session->consumeGoogleLink(27));

        $expired = GoogleOidcFlow::begin(
            self::CLIENT_ID, 'https://opgateway.test', 'login', null,
        );
        $_SESSION['opgateway_google_oidc']['created_at'] = time() - 301;
        self::assertNull(GoogleOidcFlow::take($expired['state']));
        self::assertTrue(session_destroy());
    }

    public function testGoogleIdTokenRequiresGoogleSignatureAndValidatedClaims(): void
    {
        $rsa = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048,
        ]);
        self::assertNotFalse($rsa);
        $details = openssl_pkey_get_details($rsa);
        self::assertIsArray($details);
        $publicKey = $details['key'];
        $b64 = static fn (string $raw): string => rtrim(strtr(
            base64_encode($raw), '+/', '-_',
        ), '=');
        $make = static function (array $claims) use ($b64, $rsa): string {
            $header = $b64(json_encode(['alg' => 'RS256', 'kid' => 'test-key'],
                JSON_THROW_ON_ERROR));
            $payload = $b64(json_encode($claims, JSON_THROW_ON_ERROR));
            $signed = $header . '.' . $payload;
            $signature = '';
            self::assertTrue(openssl_sign($signed, $signature, $rsa, OPENSSL_ALGO_SHA256));
            return $signed . '.' . $b64($signature);
        };
        $now = time();
        $nonce = str_repeat('a', 64);
        $claims = [
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID, 'sub' => '100000000000000000001',
            'email' => 'User@Example.com', 'email_verified' => true,
            'exp' => $now + 1800, 'iat' => $now, 'nonce' => $nonce,
        ];
        $verifier = new GoogleOidcIdTokenVerifier();
        $certs = ['test-key' => $publicKey];
        self::assertSame(
            ['sub' => '100000000000000000001', 'email' => 'user@example.com',
                'email_verified' => true, 'email_authoritative' => false],
            $verifier->verify($make($claims), self::CLIENT_ID, $nonce, $certs),
        );
        self::assertSame(
            ['sub' => '100000000000000000001', 'email' => 'user@gmail.com',
                'email_verified' => true, 'email_authoritative' => true],
            $verifier->verify(
                $make([...$claims, 'email' => 'User@Gmail.com']),
                self::CLIENT_ID, $nonce, $certs,
            ),
        );
        self::assertSame(
            ['sub' => '100000000000000000001', 'email' => 'user@team.example',
                'email_verified' => true, 'email_authoritative' => true],
            $verifier->verify(
                $make([...$claims, 'email' => 'user@team.example', 'hd' => 'team.example']),
                self::CLIENT_ID, $nonce, $certs,
            ),
        );
        self::assertSame(
            ['sub' => '100000000000000000001', 'email' => 'user@external.example',
                'email_verified' => true, 'email_authoritative' => false],
            $verifier->verify(
                $make([...$claims, 'email' => 'user@external.example', 'hd' => 'team.example']),
                self::CLIENT_ID, $nonce, $certs,
            ),
        );
        self::assertNull($verifier->verify(
            $make([...$claims, 'hd' => 'not a domain']),
            self::CLIENT_ID, $nonce, $certs,
        ));
        self::assertNull($verifier->verify(
            $make($claims), self::CLIENT_ID, str_repeat('b', 64), $certs,
        ));
        self::assertNull($verifier->verify(
            $make([...$claims, 'aud' => 'another-client']),
            self::CLIENT_ID, $nonce, $certs,
        ));
        self::assertNull($verifier->verify(
            $make([...$claims, 'email_verified' => false]),
            self::CLIENT_ID, $nonce, $certs,
        ));
        self::assertNull($verifier->verify(
            $make([...$claims, 'email_verified' => 'true']),
            self::CLIENT_ID, $nonce, $certs,
        ));
        self::assertNull($verifier->verify(
            $make([...$claims, 'exp' => $now - 1]),
            self::CLIENT_ID, $nonce, $certs,
        ));
        self::assertNull($verifier->verify(
            $make([...$claims, 'iss' => 'https://evil.example']),
            self::CLIENT_ID, $nonce, $certs,
        ));
        self::assertNull($verifier->verify(
            $make($claims), self::CLIENT_ID, $nonce, $certs, true,
        ));
        self::assertSame(
            ['sub' => '100000000000000000001', 'email' => 'user@example.com',
                'email_verified' => true, 'email_authoritative' => false],
            $verifier->verify(
                $make([...$claims, 'auth_time' => $now - 299]),
                self::CLIENT_ID, $nonce, $certs, true,
            ),
        );
        self::assertNull($verifier->verify(
            $make([...$claims, 'auth_time' => $now - 301]),
            self::CLIENT_ID, $nonce, $certs, true,
        ));
        self::assertNull($verifier->verify(
            $make($claims), self::CLIENT_ID, $nonce, ['other' => $publicKey],
        ));
        $signed = $make($claims);
        $parts = explode('.', $signed);
        $parts[1] = $b64(json_encode([...$claims, 'sub' => 'attacker'],
            JSON_THROW_ON_ERROR));
        self::assertNull($verifier->verify(
            implode('.', $parts), self::CLIENT_ID, $nonce, $certs,
        ));
    }
}
