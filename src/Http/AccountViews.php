<?php

declare(strict_types=1);

namespace OPGateway\Http;

use InvalidArgumentException;
use OPGateway\View\TwigRenderer;

/**
 * The single account presentation layer: shared TailAdmin/Twig templates.
 *
 * Templates escape all account metadata. This class never performs
 * authorization, reads a database, or stores secrets in browser state.
 * Existing form names and one-time key display contracts are unchanged.
 */
final class AccountViews
{
    public static function login(
        string $csrfToken, bool $failed = false, bool $allowRegistration = false,
        bool $googleAvailable = false, array $captcha = [],
    ): string
    {
        self::validateCsrf($csrfToken);
        return self::render('account/login.html.twig', [
            'page_title' => 'Sign in to your account',
            'page_eyebrow' => 'Account workspace',
            'page_description' => 'Sign in with your email and password.',
            'csrf_token' => $csrfToken,
            'show_error' => $failed,
            'allow_registration' => $allowRegistration,
            'google_available' => $googleAvailable,
            'captcha' => $captcha,
        ]);
    }

    public static function register(
        string $csrfToken, bool $googleAvailable = false,
        bool $passwordRegistrationAvailable = true,
    ): string
    {
        self::validateCsrf($csrfToken);
        return self::render('account/register.html.twig', [
            'page_title' => 'Create your account',
            'page_eyebrow' => 'Join OPGateway',
            'page_description' => $passwordRegistrationAvailable
                ? 'Use Google, or enter your email and choose a password.'
                : 'Use Google to create your account.',
            'csrf_token' => $csrfToken,
            'google_available' => $googleAvailable,
            'password_registration_available' => $passwordRegistrationAvailable,
        ]);
    }

    public static function forgotPassword(string $csrfToken, array $captcha = [], bool $failed = false): string
    {
        self::validateCsrf($csrfToken);
        return self::render('account/forgot-password.html.twig', [
            'page_title' => 'Forgot password',
            'page_eyebrow' => 'Account recovery',
            'page_description' => 'Enter your email to receive a reset link.',
            'csrf_token' => $csrfToken,
            'captcha' => $captcha,
            'show_error' => $failed,
        ]);
    }

    public static function resetPasswordForm(string $csrfToken, string $token): string
    {
        self::validateCsrf($csrfToken);
        if (preg_match('/^[a-f0-9]{64}$/iD', $token) !== 1) {
            return self::resetResult(false);
        }
        return self::render('account/reset-password.html.twig', [
            'page_title' => 'Reset password',
            'page_eyebrow' => 'Account recovery',
            'page_description' => 'Choose a new password.',
            'csrf_token' => $csrfToken,
            'token' => strtolower($token),
        ]);
    }

    public static function resetRequestAccepted(): string
    {
        return self::status(
            'Check your email', 'Account recovery',
            'If that address has an active account, a reset link has been sent.',
            '/account/login', 'Back to sign in',
        );
    }

    public static function resetRequestLimited(): string
    {
        return self::status(
            'Too many requests', 'Account recovery',
            'You have requested too many reset links. Please try again later. Request limits reset each hour.',
            '/account/login', 'Back to sign in',
        );
    }

    public static function resetResult(bool $success): string
    {
        return $success
            ? self::status(
                'Password changed', 'Account recovery',
                'Your password has been changed. Sign in with your new password.',
                '/account/login', 'Sign in',
            )
            : self::status(
                'Reset link unavailable', 'Account recovery',
                'The link is invalid or expired. Request a new one.',
                '/account/forgot-password', 'Request a new link',
            );
    }

    public static function registrationAccepted(): string
    {
        return self::status(
            'Check your email',
            'Verification email',
            'If that address is eligible, a verification email has been sent. Check your inbox and spam folder, or request a new link.',
            '/account/register',
            'Back to registration',
        );
    }

    public static function verifyForm(string $csrfToken, string $token): string
    {
        self::validateCsrf($csrfToken);
        if (preg_match('/^[0-9a-fA-F]{64}$/D', $token) !== 1) {
            return self::verified(false);
        }
        return self::render('account/verify.html.twig', [
            'page_title' => 'Verifying your email',
            'page_eyebrow' => 'Email verification',
            'page_description' => 'Keep this page open while we verify your email address.',
            'csrf_token' => $csrfToken,
            'token' => strtolower($token),
        ]);
    }

    public static function verified(bool $success): string
    {
        return $success
            ? self::status(
                'Email verified', 'Registration complete',
                'Your email address has been verified successfully. You can now sign in.',
                '/account/login', 'Sign in',
            )
            : self::status(
                'Verification unavailable', 'Email verification',
                'The link is invalid or expired. Request another verification email from the registration page.',
                '/account/register', 'Back to registration',
            );
    }

    /**
     * @param array{
     *   email:string,
     *   clients:list<array{id:int,label:string,status:string}>,
     *   credentials:list<array{id:int,client_id:int,prefix:string,revoked:bool}>,
     *   ips:list<array{id:int,client_id:int,address:string,enabled:bool}>
     * } $overview
     * @param array{configured?:bool,username?:string} $provider
     */
    public static function dashboard(
        string $csrfToken, array $overview, bool $keysEnabled,
        bool $integrationEnabled = false, array $provider = [], string $section = 'overview',
        bool $googleStepUp = false,
    ): string {
        self::validateCsrf($csrfToken);
        $sections = [
            'overview' => 'Overview',
            'provider' => 'Openprovider account',
            'clients' => 'API clients',
            'keys' => 'API keys',
            'ips' => 'Allowed IPs',
        ];
        if (!isset($sections[$section])) {
            throw new InvalidArgumentException('Unknown account section.');
        }
        return self::render('account/dashboard.html.twig', [
            'section_title' => $sections[$section],
            'active_section' => $section,
            'csrf_token' => $csrfToken,
            'workspace_home' => '/account',
            'workspace_name' => 'Account',
            'workspace_footer' => 'OPGateway · Account',
            'logout_path' => '/account/logout',
            'user_label' => $overview['email'],
            'overview' => $overview,
            'keys_enabled' => $keysEnabled,
            'integration_enabled' => $integrationEnabled,
            'provider' => $provider,
            'google_step_up' => $googleStepUp,
        ]);
    }

    /** Account sign-in and recovery methods. */
    public static function security(
        string $csrfToken, string $email, bool $hasPassword,
        bool $googleAvailable, ?string $linkedEmail,
    ): string {
        self::validateCsrf($csrfToken);
        return self::render('account/google.html.twig', [
            'active_section' => 'security',
            'section_title' => 'Account Security',
            'csrf_token' => $csrfToken,
            'workspace_home' => '/account',
            'workspace_name' => 'Account',
            'workspace_footer' => 'OPGateway · Account',
            'logout_path' => '/account/logout',
            'user_label' => $email,
            'google_available' => $googleAvailable,
            'linked_email' => $linkedEmail,
            'has_password' => $hasPassword,
        ]);
    }

    /** Backward-compatible view helper used by older focused tests. */
    public static function google(
        string $csrfToken, string $email, bool $googleAvailable, ?string $linkedEmail,
    ): string {
        return self::security($csrfToken, $email, true, $googleAvailable, $linkedEmail);
    }

    public static function googleConfirmation(string $csrfToken, string $token): string
    {
        self::validateCsrf($csrfToken);
        return self::render('account/google-confirm.html.twig', [
            'page_title' => 'Confirm Google sign-in',
            'page_eyebrow' => 'Account security',
            'page_description' => 'Confirm the one-time request before Google is connected.',
            'csrf_token' => $csrfToken,
            'token' => preg_match('/^[0-9a-f]{64}$/D', $token) === 1 ? $token : '',
        ]);
    }

    public static function googleContinuation(string $authorizationUrl): string
    {
        $parts = parse_url($authorizationUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'accounts.google.com'
            || ($parts['path'] ?? null) !== '/o/oauth2/v2/auth'
            || !is_string($parts['query'] ?? null) || $parts['query'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Invalid Google authorization URL.');
        }
        return self::render('account/google-continue.html.twig', [
            'page_title' => 'Continue to Google',
            'page_eyebrow' => 'Account security',
            'page_description' => 'Complete the verified Google authorization request.',
            'authorization_url' => $authorizationUrl,
        ]);
    }

    public static function googleStatus(string $title, string $message): string
    {
        return self::status($title, 'Google sign-in', $message,
            '/account/login', 'Back to sign in');
    }

    /** An issued bearer key is shown on exactly one POST response, never a GET. */
    public static function issued(string $key): string
    {
        if (preg_match('/^opg_[0-9a-f]{16}\.[0-9a-f]{64}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Invalid generated API key.');
        }
        return self::render('account/issued.html.twig', [
            'page_title' => 'API key created',
            'page_eyebrow' => 'One-time API credentials',
            'page_description' => 'Keep your new key somewhere secure. It cannot be recovered after this page is closed.',
            'key' => $key,
        ]);
    }

    private static function status(
        string $title, string $eyebrow, string $message,
        string $destination, string $actionLabel,
    ): string {
        return self::render('account/status.html.twig', [
            'page_title' => $title,
            'page_eyebrow' => $eyebrow,
            'page_description' => 'Account verification and access',
            'status_message' => $message,
            'destination' => $destination,
            'action_label' => $actionLabel,
        ]);
    }

    /** @param array<string,mixed> $data */
    private static function render(string $template, array $data): string
    {
        return (new TwigRenderer(dirname(__DIR__, 2) . '/templates'))->render($template, $data);
    }

    private static function validateCsrf(string $token): void
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Invalid CSRF token.');
        }
    }
}
