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

                    public function execute(?array $params = null): bool
                    {
                        if (str_contains($this->query, 'INSERT INTO login_rate_limits')) {
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
        self::assertMatchesRegularExpression('/What is [0-9]+ \+ [0-9]+\?/',
            $challenge['prompt']);
        preg_match('/What is ([0-9]+) \+ ([0-9]+)\?/', $challenge['prompt'], $matches);
        $answer = (string) ((int) $matches[1] + (int) $matches[2]);
        $fields = ['captcha_id' => $challenge['challenge_id'], 'captcha_answer' => $answer];
        self::assertTrue($captcha->verify($fields, '192.0.2.7'));
        self::assertFalse($captcha->verify($fields, '192.0.2.7'),
            'A consumed challenge may not be reused.');

        $bad = $captcha->challenge();
        self::assertFalse($captcha->verify([
            'captcha_id' => $bad['challenge_id'], 'captcha_answer' => '99',
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
        preg_match('/What is ([0-9]+) \+ ([0-9]+)\?/', $a['prompt'], $matches);
        self::assertTrue($account->verify([
            'captcha_id' => $a['challenge_id'],
            'captcha_answer' => (string) ((int) $matches[1] + (int) $matches[2]),
        ], '192.0.2.8'));
        preg_match('/What is ([0-9]+) \+ ([0-9]+)\?/', $b['prompt'], $matches);
        self::assertTrue($admin->verify([
            'captcha_id' => $b['challenge_id'],
            'captcha_answer' => (string) ((int) $matches[1] + (int) $matches[2]),
        ], '192.0.2.8'));
        self::assertTrue(session_destroy());
    }

    /** @return array{captcha_id:string,captcha_answer:string} */
    private static function offlineAnswerFromHtml(string $html): array
    {
        self::assertSame(1, preg_match(
            '/name="captcha_id" value="([0-9a-f]{32})"/', $html, $id,
        ));
        self::assertSame(1, preg_match(
            '/Verification: What is ([0-9]+) \+ ([0-9]+)\?/', $html, $numbers,
        ));
        return [
            'captcha_id' => $id[1],
            'captcha_answer' => (string) ((int) $numbers[1] + (int) $numbers[2]),
        ];
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
            \OPGateway\Identity\PasswordSessionStamp::fromHash($hash),
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
            ...$fields, 'captcha_answer' => '99',
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

    public function testSettingsAreRealSeparateRoutesAndPages(): void
    {
        foreach ([
            '/admin/settings',
            '/admin/settings/login',
            '/admin/settings/login/google',
            '/admin/settings/login/captcha',
        ] as $path) {
            self::assertSame($path, BrowserGetPath::normalize('GET', $path . '/'));
        }
        $twig = new TwigRenderer(dirname(__DIR__) . '/templates');
        $data = [
            'active_section' => 'settings', 'section_title' => 'Settings',
            'administrator_label' => 'Administrator', 'csrf_token' => str_repeat('a', 64),
            'captcha' => ['provider' => 'offline', 'site_key' => '', 'secret_set' => false],
            'google' => ['client_id' => '', 'secret_set' => false, 'enabled' => true],
        ];
        $settings = $twig->render('admin/settings.html.twig', $data);
        self::assertStringContainsString('href="/admin/settings/login"', $settings);
        self::assertStringContainsString('href="/admin/settings/smtp"', $settings);
        $login = $twig->render('admin/login-settings.html.twig', $data);
        self::assertStringContainsString('href="/admin/settings/login/google"', $login);
        self::assertStringContainsString('href="/admin/settings/login/captcha"', $login);
        $captcha = $twig->render('admin/captcha.html.twig', $data);
        self::assertStringContainsString('name="provider" value="offline"', $captcha);
        self::assertStringContainsString('name="provider" value="recaptcha"', $captcha);
        self::assertStringContainsString('name="current_password"', $captcha);
    }

    public function testLoginPagesRenderSelectedCaptchaWithoutRemovingPassword(): void
    {
        $token = str_repeat('a', 64);
        $offline = AccountViews::login($token, false, true, true, [
            'mode' => 'offline', 'prompt' => 'What is 2 + 3?',
            'challenge_id' => str_repeat('b', 32),
        ]);
        self::assertStringContainsString('name="password"', $offline);
        self::assertStringContainsString('name="captcha_id"', $offline);
        self::assertStringContainsString('name="captcha_answer"', $offline);
        self::assertStringContainsString('Continue with Google', $offline);

        $google = AccountViews::login($token, false, true, true, [
            'mode' => 'recaptcha', 'site_key' => str_repeat('S', 35),
        ]);
        self::assertStringContainsString('class="g-recaptcha"', $google);
        self::assertStringContainsString('https://www.google.com/recaptcha/api.js', $google);
    }
}
