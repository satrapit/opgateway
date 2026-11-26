<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use InvalidArgumentException;
use OPGateway\Google\PdoGoogleOAuthSettings;
use OPGateway\Http\AccountViews;
use OPGateway\Http\AccountBrowserAdapter;
use OPGateway\Http\BrowserAuthController;
use OPGateway\Http\OwnerOverviewProvider;
use OPGateway\Http\PrivateBrowserAdapter;
use OPGateway\Http\BrowserGetPath;
use OPGateway\Identity\BrowserSession;
use OPGateway\Identity\GuardedLoginService;
use OPGateway\Identity\LoginAccountRepository;
use OPGateway\Identity\LoginAuthenticator;
use OPGateway\Identity\AdministratorAuthorization;
use OPGateway\Identity\PasswordHasher;
use OPGateway\Security\LoginCaptcha;
use OPGateway\Security\OfflineCaptchaImage;
use OPGateway\Security\PdoLoginCaptchaSettings;
use OPGateway\Security\SealedSecret;
use OPGateway\Security\LoginAttemptLimiter;
use OPGateway\Security\SecurityEventRecorder;
use OPGateway\View\TwigRenderer;
use OPGateway\View\TwigAdminPageRenderer;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Settings use the existing MySQL table and password login keeps server checks. */
final class LoginSettingsPolicyTest extends TestCase
{
    private static function database(): PDO
    {
        return new class extends PDO {
            /** @var array<string,string> */
            public array $settings = [];
            public int $attempts = 0;

            public function __construct()
            {
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return new class($this, $query) extends PDOStatement {
                    public function __construct(private PDO $db, private string $query)
                    {
                    }

                    /** @var array<int|string,mixed> */
                    private array $bound = [];

                    public function bindValue(
                        string|int $param, mixed $value, int $type = PDO::PARAM_STR,
                    ): bool {
                        $this->bound[$param] = $value;
                        return true;
                    }

                    public function execute(?array $params = null): bool
                    {
                        if (str_contains($this->query, 'INSERT INTO login_rate_limits')) {
                            // A PDOStatement test double has no internal
                            // driver, so bindValue must be implemented here.
                            // Verify the production limiter binds a binary
                            // SHA-256 bucket and integer time window.
                            TestCase::assertIsString($this->bound[1] ?? null);
                            TestCase::assertSame(32, strlen($this->bound[1]));
                            TestCase::assertIsInt($this->bound[2] ?? null);
                            $this->db->attempts++;
                        } elseif (str_contains($this->query, "VALUES ('google.client_id'")) {
                            $this->db->settings['google.client_id'] = $params[0];
                            $this->db->settings['google.client_secret'] = $params[1];
                        } elseif (str_contains($this->query, "VALUES ('google.enabled'")) {
                            $this->db->settings['google.enabled'] = $params[0];
                        } elseif (str_contains($this->query, 'INSERT INTO settings')) {
                            $this->db->settings[$params[0]] = $params[1];
                        }
                        return true;
                    }

                    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
                    {
                        return $this->db->settings;
                    }

                    public function fetchColumn(int $column = 0): mixed
                    {
                        return $this->db->attempts;
                    }
                };
            }
        };
    }

    public function testCaptchaConfigurationStoresKeysOutsideEnvAndEncryptsSecret(): void
    {
        $db = self::database();
        $vault = new SealedSecret(str_repeat('a', 64));
        $settings = new PdoLoginCaptchaSettings($db, $vault);
        self::assertSame('offline', $settings->summary()['provider']);
        self::assertSame(false, $settings->summary()['secret_set']);
        $site = str_repeat('S', 35);
        $secret = str_repeat('T', 35);
        $settings->save('recaptcha', $site, $secret);
        self::assertSame('recaptcha', $settings->summary()['provider']);
        self::assertSame($site, $settings->summary()['site_key']);
        self::assertTrue($settings->summary()['secret_set']);
        self::assertStringStartsWith('v1:', $db->settings['login.recaptcha_secret']);
        self::assertStringNotContainsString($secret, $db->settings['login.recaptcha_secret']);
        self::assertSame(['site_key' => $site, 'secret' => $secret],
            $settings->recaptchaCredentials());

        $settings->save('offline', '', '');
        self::assertSame('offline', $settings->summary()['provider']);
        self::assertNull($settings->recaptchaCredentials());
        $settings->save('recaptcha', '', '');
        self::assertSame($secret, $settings->recaptchaCredentials()['secret']);
    }

    public function testCannotChooseRecaptchaWithoutBothKeys(): void
    {
        $settings = new PdoLoginCaptchaSettings(self::database(),
            new SealedSecret(str_repeat('b', 64)));
        $this->expectException(InvalidArgumentException::class);
        $settings->save('recaptcha', '', '');
    }

    public function testUnknownCaptchaProviderIsRejected(): void
    {
        $db = self::database();
        $db->settings['login.captcha_provider'] = 'disabled';
        $settings = new PdoLoginCaptchaSettings($db,
            new SealedSecret(str_repeat('a', 64)));
        $this->expectException(RuntimeException::class);
        $settings->summary();
    }

    public function testGoogleSsoCanBeDisabledWithoutDeletingPasswordOrSecret(): void
    {
        $db = self::database();
        $vault = new SealedSecret(str_repeat('a', 64));
        $settings = new PdoGoogleOAuthSettings($db, $vault);
        $client = '123456789012-abcdefghijk.apps.googleusercontent.com';
        $settings->save($client, 'google-oauth-secret', true);
        self::assertTrue($settings->configured());
        self::assertSame($client, $settings->credentials()['client_id']);
        $settings->save($client, '', false);
        self::assertFalse($settings->configured());
        self::assertNull($settings->credentials());
        self::assertTrue($settings->summary()['secret_set']);
        $settings->save($client, '', true);
        self::assertTrue($settings->configured());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOfflineChallengeIsSessionBoundTimedAndSingleUse(): void
    {
        $db = self::database();
        $settings = new PdoLoginCaptchaSettings($db,
            new SealedSecret(str_repeat('a', 64)));
        $session = new BrowserSession();
        $session->start(true);
        $captcha = new LoginCaptcha(
            $settings, $db, str_repeat('x', 32), 'https://opgateway.arsamnet.com',
            'account',
        );
        self::assertSame(['captcha_id', 'captcha_answer'], $captcha->fields());
        $challenge = $captcha->challenge();
        self::assertSame('offline', $challenge['mode']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $challenge['challenge_id']);
        self::assertSame('/account/captcha/image', $challenge['image_url']);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $captcha->image());
        self::assertNull($challenge['prompt'] ?? null);
        $answer = 'ABC234';
        $_SESSION['opgateway_login_captcha_account']['answer_hash'] =
            hash_hmac('sha256', $answer,
                str_repeat('x', 32) . $challenge['challenge_id']);
        $fields = ['captcha_id' => $challenge['challenge_id'], 'captcha_answer' => $answer];
        self::assertTrue($captcha->verify($fields, '192.0.2.7'));
        self::assertFalse($captcha->verify($fields, '192.0.2.7'),
            'A consumed challenge may not be reused.');

        $bad = $captcha->challenge();
        self::assertFalse($captcha->verify([
            'captcha_id' => $bad['challenge_id'], 'captcha_answer' => 'BAD234',
        ], '192.0.2.7'));
        self::assertGreaterThanOrEqual(3, $db->attempts);
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAdminAndAccountOfflineChallengesDoNotOverwriteEachOther(): void
    {
        $db = self::database();
        $settings = new PdoLoginCaptchaSettings($db,
            new SealedSecret(str_repeat('a', 64)));
        (new BrowserSession())->start(true);
        $account = new LoginCaptcha($settings, $db, str_repeat('x', 32),
            'https://opgateway.arsamnet.com', 'account');
        $admin = new LoginCaptcha($settings, $db, str_repeat('x', 32),
            'https://opgateway.arsamnet.com', 'admin');
        $a = $account->challenge();
        $b = $admin->challenge();
        $_SESSION['opgateway_login_captcha_account']['answer_hash'] =
            hash_hmac('sha256', 'ABC234', str_repeat('x', 32) . $a['challenge_id']);
        $_SESSION['opgateway_login_captcha_admin']['answer_hash'] =
            hash_hmac('sha256', 'DEF567', str_repeat('x', 32) . $b['challenge_id']);
        self::assertTrue($account->verify([
            'captcha_id' => $a['challenge_id'],
            'captcha_answer' => 'ABC234',
        ], '192.0.2.8'));
        self::assertTrue($admin->verify([
            'captcha_id' => $b['challenge_id'],
            'captcha_answer' => 'DEF567',
        ], '192.0.2.8'));
        self::assertTrue(session_destroy());
    }

    /** @return array{captcha_id:string,captcha_answer:string} */
    private static function offlineAnswerFromHtml(string $html): array
    {
        self::assertSame(1, preg_match(
            '/name="captcha_id" value="([0-9a-f]{32})"/', $html, $id,
        ));
        self::assertStringContainsString('/captcha/image', $html);
        // The only test setup hook is direct manipulation of this isolated
        // PHP session. Production markup and session keep no raw answer.
        $scope = str_contains($html, '/admin/captcha/image') ? 'admin' : 'account';
        $solution = 'ABC234';
        $_SESSION['opgateway_login_captcha_' . $scope]['answer_hash'] =
            hash_hmac('sha256', $solution, str_repeat('x', 32) . $id[1]);
        return ['captcha_id' => $id[1], 'captcha_answer' => $solution];
    }

    /**
     * @return array{0:LoginAccountRepository,1:GuardedLoginService}
     */
    private function loginDependencies(): array
    {
        $accounts = $this->createStub(LoginAccountRepository::class);
        $hash = password_hash('example-password-123', PASSWORD_DEFAULT);
        $accounts->method('findEligibleByEmail')->willReturn([
            'id' => 17, 'password_hash' => $hash,
        ]);
        $accounts->method('isEligible')->willReturn(true);
        $accounts->method('currentSessionStamp')->willReturn(
            \OPGateway\Identity\AuthenticationSessionVersion::stamp(1),
        );
        $limiter = $this->createStub(LoginAttemptLimiter::class);
        $limiter->method('allow')->willReturn(true);
        return [
            $accounts,
            new GuardedLoginService(
                new LoginAuthenticator($accounts, new PasswordHasher()),
                $limiter, $this->createStub(SecurityEventRecorder::class),
            ),
        ];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAccountPasswordPostRequiresRealOfflineChallenge(): void
    {
        $db = self::database();
        $settings = new PdoLoginCaptchaSettings($db,
            new SealedSecret(str_repeat('a', 64)));
        (new BrowserSession())->start(true);
        // A fresh instance shares the current underlying PHP session.
        $session = new BrowserSession();
        [$accounts, $login] = $this->loginDependencies();
        $overview = $this->createStub(OwnerOverviewProvider::class);
        $captcha = new LoginCaptcha($settings, $db, str_repeat('x', 32),
            'https://opgateway.arsamnet.com', 'account');
        $adapter = new AccountBrowserAdapter($session, $login, $accounts,
            $overview, null, null, false, false, $captcha);
        $send = static fn (string $method, ?string $body = null): array =>
            $adapter->dispatch($method, '/account/login', true,
                'application/x-www-form-urlencoded', '192.0.2.31',
                str_repeat('a', 32), $body);
        $get = $send('GET');
        self::assertSame(200, $get['status']);
        $answer = self::offlineAnswerFromHtml($get['body']);
        $csrf = $session->csrfToken();
        $fields = ['_csrf' => $csrf, 'email' => 'owner@example.com',
            'password' => 'example-password-123', ...$answer];
        self::assertSame(403, $send('POST', http_build_query([
            ...$fields, 'captcha_answer' => 'BAD234',
        ]))['status']);
        // A failed challenge is consumed; use the new form returned by POST.
        $fresh = $send('GET');
        $answer = self::offlineAnswerFromHtml($fresh['body']);
        self::assertSame(303, $send('POST',
            http_build_query([...$fields, ...$answer]))['status']);
        self::assertTrue(session_destroy());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAdministratorPasswordPostRequiresOfflineChallenge(): void
    {
        $db = self::database();
        $settings = new PdoLoginCaptchaSettings($db,
            new SealedSecret(str_repeat('a', 64)));
        (new BrowserSession())->start(true);
        $session = new BrowserSession();
        [$accounts, $login] = $this->loginDependencies();
        $admins = $this->createStub(AdministratorAuthorization::class);
        $admins->method('isAdministrator')->willReturn(true);
        $auth = new BrowserAuthController($session, $login, $accounts, $admins);
        $pages = new TwigAdminPageRenderer(
            new TwigRenderer(dirname(__DIR__) . '/templates'),
        );
        $captcha = new LoginCaptcha($settings, $db, str_repeat('x', 32),
            'https://opgateway.arsamnet.com', 'admin');
        $adapter = new PrivateBrowserAdapter($session, $auth, $accounts, $admins,
            null, null, $pages, $captcha);
        $send = static fn (string $method, ?string $body = null): array =>
            $adapter->dispatch($method, '/admin/login', true,
                'application/x-www-form-urlencoded', '192.0.2.32',
                str_repeat('a', 32), $body);
        $get = $send('GET');
        self::assertSame(200, $get['status']);
        $answer = self::offlineAnswerFromHtml($get['body']);
        $csrf = $session->csrfToken();
        $fields = ['_csrf' => $csrf, 'email' => 'admin@example.com',
            'password' => 'example-password-123', ...$answer];
        self::assertSame(403, $send('POST', http_build_query([
            ...$fields, '_csrf' => str_repeat('0', 64),
        ]))['status']);
        // Rejection of a bad CSRF token must not consume the CAPTCHA.
        self::assertSame(303, $send('POST', http_build_query($fields))['status']);
        self::assertTrue(session_destroy());
    }

    public function testSettingsHaveTailAdminParentAndOnlyGroupedPages(): void
    {
        foreach ([
            '/admin/settings/security', '/admin/settings/login/google',
            '/admin/settings/smtp', '/admin/users/invite',
        ] as $page) {
            self::assertSame($page, BrowserGetPath::normalize('GET', $page . '/'));
        }
        $twig = new TwigRenderer(dirname(__DIR__) . '/templates');
        $data = [
            'active_section' => 'security', 'section_title' => 'Security',
            'administrator_label' => 'Administrator', 'csrf_token' => str_repeat('a', 64),
            'captcha' => ['provider' => 'offline', 'site_key' => '', 'secret_set' => false],
        ];
        $html = $twig->render('admin/captcha.html.twig', $data);
        self::assertStringContainsString('name="provider" value="offline"', $html);
        self::assertStringContainsString('name="provider" value="recaptcha"', $html);
        self::assertStringNotContainsString('name="current_password"', $html);
        self::assertStringContainsString('id="opg-settings-submenu"', $html);
        self::assertStringContainsString('#opg-icon-settings', $html);
        self::assertStringContainsString('href="/admin/settings/smtp"', $html);
        self::assertStringNotContainsString('All settings', $html);
    }

    public function testAdminSettingsUseSessionCsrfInsteadOfRepeatedPassword(): void
    {
        $twig = new TwigRenderer(dirname(__DIR__) . '/templates');
        $base = [
            'administrator_label' => 'Administrator',
            'section_title' => 'Settings',
            'csrf_token' => str_repeat('a', 64),
        ];
        foreach ([
            ['admin/captcha.html.twig', [
                'active_section' => 'security',
                'captcha' => ['provider' => 'offline', 'site_key' => '',
                    'secret_set' => false],
            ]],
            ['admin/google.html.twig', [
                'active_section' => 'google',
                'google' => ['client_id' => '', 'enabled' => true,
                    'secret_set' => false],
                'redirect_uri' => 'https://opgateway.arsamnet.com/account/google/callback',
            ]],
            ['admin/smtp.html.twig', [
                'active_section' => 'smtp',
                'gmail_actions_enabled' => false,
                'smtp' => ['host' => '', 'port' => 587, 'encryption' => 'starttls',
                    'username' => '', 'from_email' => '', 'from_name' => '',
                    'password_set' => false],
            ]],
        ] as [$path, $vars]) {
            $html = $twig->render($path, [...$base, ...$vars]);
            self::assertStringContainsString('name="_csrf"', $html, $path);
            self::assertStringNotContainsString('name="current_password"', $html, $path);
        }

        foreach ([
            'AdminLoginSettingsController.php',
            'GoogleAdminSettingsController.php',
            'SmtpAdminController.php',
            'AdminUserInvitationController.php',
        ] as $filename) {
            $source = file_get_contents(dirname(__DIR__) . '/src/Http/' . $filename);
            self::assertIsString($source);
            self::assertStringContainsString('->csrfValid(', $source);
            self::assertStringContainsString('->isAdministrator(', $source);
            self::assertStringNotContainsString('current_password', $source);
        }
    }

    public function testCaptchaGeneratorProducesRandomizedPngWithoutPlaintext(): void
    {
        self::assertMatchesRegularExpression(
            '/^[A-HJ-NP-Z2-9]{6}$/', OfflineCaptchaImage::randomAnswer(),
        );
        $first = OfflineCaptchaImage::render('ABC234');
        $second = OfflineCaptchaImage::render('ABC234');
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $first);
        self::assertSame(300, unpack('N', substr($first, 16, 4))[1]);
        self::assertSame(106, unpack('N', substr($first, 20, 4))[1]);
        self::assertSame(0, ord($first[25]), 'PNG should be compact grayscale');
        self::assertLessThan(45000, strlen($first));
        self::assertNotSame($first, $second);
        self::assertStringNotContainsString('ABC234', $first);
    }

    public function testLoginPagesRenderSelectedCaptchaWithoutRemovingPassword(): void
    {
        $token = str_repeat('a', 64);
        $offline = AccountViews::login($token, false, true, true, [
            'mode' => 'offline', 'image_url' => '/account/captcha/image',
            'challenge_id' => str_repeat('b', 32),
        ]);
        self::assertStringContainsString('name="password"', $offline);
        self::assertStringContainsString('name="captcha_id"', $offline);
        self::assertStringContainsString('name="captcha_answer"', $offline);
        self::assertStringContainsString('src="/account/captcha/image"', $offline);
        self::assertStringNotContainsString('What is', $offline);
        self::assertStringContainsString('Continue with Google', $offline);

        $google = AccountViews::login($token, false, true, true, [
            'mode' => 'recaptcha', 'site_key' => str_repeat('S', 35),
        ]);
        self::assertStringContainsString('class="g-recaptcha"', $google);
        self::assertStringContainsString('https://www.google.com/recaptcha/api.js', $google);
    }
}
