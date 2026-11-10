<?php

declare(strict_types=1);

namespace OPGateway\Http;

use InvalidArgumentException;

/**
 * Server-rendered owner account pages. All database-originated strings are
 * escaped for HTML, and no scripts, remote assets or analytics are loaded.
 *
 * A newly issued bearer key is rendered in a POST response exactly once.
 * It is never part of a redirect URL, a persistent session or a GET page.
 */
final class AccountViews
{
    public static function login(string $csrfToken, bool $failed = false, bool $allowRegistration = false): string
    {
        $csrf = self::csrf($csrfToken);
        $notice = $failed ? '<p role="alert">Sign-in was not successful.</p>' : '';
        $signupLink = $allowRegistration ? '<p><a href="/account/register">Create an account</a></p>' : '';
        $body = <<<HTML
<main>
  <h1>OPGateway account</h1>
  <p>Sign in to manage your Openprovider connection, IPs and API clients.</p>
  {$signupLink}
  {$notice}
  <form method="post" action="/account/login">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <label for="owner-email">Email</label>
    <input id="owner-email" type="email" name="email" maxlength="254" autocomplete="username" required>
    <label for="owner-password">Password</label>
    <input id="owner-password" type="password" name="password" autocomplete="current-password" required>
    <button type="submit">Sign in</button>
  </form>
</main>
HTML;
        return self::page('Account sign in', $body);
    }

    /** Public signup is opt-in. No account-enumerating messages. */
    public static function register(string $csrfToken): string
    {
        $csrf = self::csrf($csrfToken);
        $body = <<<HTML
<main>
  <h1>Create your OPGateway account</h1>
  <p>Verify your email to activate your account. Passwords must be at least 12 characters.</p>
  <form method="post" action="/account/register">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <label for="registration-email">Email</label>
    <input type="email" id="registration-email" name="email" maxlength="254" autocomplete="email" required>
    <label for="registration-password">Password</label>
    <input type="password" id="registration-password" name="password" minlength="12"
      maxlength="1024" autocomplete="new-password" required>
    <button type="submit">Register and send verification email</button>
  </form>
  <section><h2>Resend a verification email</h2>
    <p>If your earlier email expired or delivery failed, request a new one.</p>
    <form method="post" action="/account/verify/resend">
      <input type="hidden" name="_csrf" value="{$csrf}">
      <label for="resend-email">Email</label>
      <input type="email" id="resend-email" name="email" maxlength="254" required>
      <button type="submit">Resend verification</button>
    </form>
  </section>
  <p><a href="/account/login">Already registered? Sign in</a></p>
</main>
HTML;
        return self::page('Create account', $body);
    }

    public static function registrationAccepted(): string
    {
        return self::page('Check your email', '<main><h1>Check your email</h1>'
            . '<p>If that address is eligible, a verification email has been sent. '
            . 'Check your inbox and spam folder, or request another email from the registration page.</p>'
            . '<p><a href="/account/register">Back to registration</a></p></main>');
    }

    /** Link scanners cannot auto-consume a verification token via GET. */
    public static function verifyForm(string $csrfToken, string $token): string
    {
        $csrf = self::csrf($csrfToken);
        if (!preg_match('/^[0-9a-fA-F]{64}$/D', $token)) {
            return self::verified(false);
        }
        $safe = self::escape(strtolower($token));
        return self::page('Confirm your email', <<<HTML
<main>
  <h1>Confirm email ownership</h1>
  <p>To complete activation, confirm this one-time verification link.</p>
  <form method="post" action="/account/verify">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <input type="hidden" name="token" value="{$safe}">
    <button type="submit">Confirm email</button>
  </form>
</main>
HTML);
    }

    public static function verified(bool $success): string
    {
        return self::page('Email verification', $success
            ? '<main><h1>Email verified</h1><p>You can now sign in.</p>'
              . '<p><a href="/account/login">Sign in</a></p></main>'
            : '<main><h1>Verification unavailable</h1><p>The link is invalid or expired.'
              . ' Request a new email from the registration page.</p>'
              . '<p><a href="/account/register">Registration</a></p></main>');
    }

    /**
     * @param array{
     *   email:string,
     *   clients:list<array{id:int,label:string,status:string}>,
     *   credentials:list<array{id:int,client_id:int,prefix:string,revoked:bool}>,
     *   ips:list<array{id:int,client_id:int,address:string,enabled:bool}>
     * } $overview
     */
    public static function dashboard(
        string $csrfToken, array $overview, bool $keysEnabled,
        bool $integrationEnabled = false, array $provider = [],
    ): string
    {
        $csrf = self::csrf($csrfToken);
        $email = self::escape($overview['email']);
        $clients = [];
        foreach ($overview['clients'] as $client) {
            $id = (int) $client['id'];
            $status = (string) $client['status'];
            $form = $keysEnabled && $status === 'active'
                ? self::issueForm($csrf, $id)
                : '';
            $clients[] = '<li>Client #' . $id . ': ' . self::escape($client['label'])
                . ' (' . self::escape($status) . ')' . $form . '</li>';
        }

        $credentials = [];
        foreach ($overview['credentials'] as $item) {
            $status = $item['revoked'] ? 'revoked / expired' : 'active';
            $form = $keysEnabled && !$item['revoked']
                ? self::revokeForm($csrf, (int) $item['client_id'], (int) $item['id'])
                : '';
            $credentials[] = '<li>Client #' . (int) $item['client_id']
                . ' / key #' . (int) $item['id']
                . ' / prefix ' . self::escape($item['prefix'])
                . ' (' . $status . ')' . $form . '</li>';
        }

        $ips = [];
        foreach ($overview['ips'] as $ip) {
            $ips[] = '<li>Client #' . (int) $ip['client_id']
                . ': ' . self::escape($ip['address'])
                . ($ip['enabled'] ? ' (approved)' : ' (pending)') . '</li>';
        }

        $state = $keysEnabled
            ? 'Issue keys only for active clients. Key material is displayed once and cannot be recovered.'
            : 'Key issuance and revocation are disabled by the operator.';
        $clientList = self::listing($clients);
        $keyList = self::listing($credentials);
        $ipList = self::listing($ips);
        $integrationHtml = $integrationEnabled
            ? self::integrationForms($csrf, $overview['clients'], $provider)
            : '';

        $body = <<<HTML
<main>
  <h1>OPGateway account</h1>
  <p>Signed in as {$email}</p>
  <p>{$state}</p>
  {$integrationHtml}
  <section><h2>Your API clients</h2>{$clientList}</section>
  <section><h2>API key prefixes (no secrets)</h2>{$keyList}</section>
  <section><h2>IP whitelist status</h2>{$ipList}</section>
  <form method="post" action="/account/logout">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <button type="submit">Sign out</button>
  </form>
</main>
HTML;
        return self::page('Your account', $body);
    }

    /** HTML only for the first successful HTTPS issuance POST. Never cache it. */
    public static function issued(string $key): string
    {
        if (preg_match('/^opg_[0-9a-f]{16}\.[0-9a-f]{64}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Invalid generated API key.');
        }
        $value = self::escape($key);
        $body = <<<HTML
<main>
  <h1>API key created</h1>
  <p role="alert"><strong>Copy this key now.</strong> It will never be shown again.
  Refreshing or leaving this page will not reveal it later. Do not paste it into chat,
  support tickets, analytics, logs or public repositories.</p>
  <p class="secret"><code>{$value}</code></p>
  <p><a href="/account">Return to your account</a></p>
</main>
HTML;
        return self::page('API key created', $body);
    }

    /**
     * @param list<array{id:int,label:string,status:string}> $clients
     * @param array{configured?:bool,username?:string} $provider
     */
    private static function integrationForms(string $csrf, array $clients, array $provider): string
    {
        $configured = ($provider['configured'] ?? false) === true;
        $username = self::escape((string) ($provider['username'] ?? ''));
        $state = $configured
            ? 'Configured. Re-enter the password to rotate your Openprovider credentials.'
            : 'Not configured. Your Openprovider password is encrypted at rest.';
        $requests = [];
        foreach ($clients as $client) {
            $id = (int) $client['id'];
            $label = self::escape($client['label']);
            $requests[] = <<<HTML
<form method="post" action="/account/ips/request">
  <input type="hidden" name="_csrf" value="{$csrf}">
  <input type="hidden" name="client_id" value="{$id}">
  <label for="requested-ip-{$id}">IP for client {$label} (#{$id})</label>
  <input id="requested-ip-{$id}" name="ip" maxlength="45" placeholder="203.0.113.42" required>
  <button type="submit">Request IP approval</button>
</form>
HTML;
        }
        $ipForms = implode("\n", $requests);
        return <<<HTML
<section>
  <h2>Openprovider account</h2>
  <p>{$state}</p>
  <form method="post" action="/account/provider/save">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <label for="provider-username">Openprovider API username</label>
    <input id="provider-username" name="username" value="{$username}" maxlength="254" autocomplete="off" required>
    <label for="provider-password">Openprovider API password</label>
    <input id="provider-password" name="password" type="password" maxlength="1024" autocomplete="new-password" required>
    <label for="provider-confirm-password">Confirm your gateway account password</label>
    <input id="provider-confirm-password" name="current_password" type="password"
      maxlength="1024" autocomplete="current-password" required>
    <button type="submit">Save encrypted credentials</button>
  </form>
</section>
<section><h2>Create an API client</h2>
  <p>The administrator must activate new clients before API keys can be issued.</p>
  <form method="post" action="/account/clients/create">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <label for="client-label">Client name</label>
    <input id="client-label" name="label" maxlength="128" required>
    <button type="submit">Request client</button>
  </form>
</section>
<section><h2>Request allowed source IPs</h2>
  <p>Only exact IPs approved by the administrator may send API requests.</p>
  {$ipForms}
</section>
HTML;
    }

    private static function issueForm(string $csrf, int $clientId): string
    {
        return '<form class="inline" method="post" action="/account/keys/issue">'
            . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
            . '<input type="hidden" name="client_id" value="' . $clientId . '">'
            . '<label for="confirm-' . $clientId . '">Confirm your password</label>'
            . '<input id="confirm-' . $clientId . '" type="password" name="password"'
            . ' autocomplete="current-password" required>'
            . '<button type="submit">Issue new key</button></form>';
    }

    private static function revokeForm(string $csrf, int $clientId, int $credentialId): string
    {
        return '<form class="inline" method="post" action="/account/keys/revoke">'
            . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
            . '<input type="hidden" name="client_id" value="' . $clientId . '">'
            . '<input type="hidden" name="credential_id" value="' . $credentialId . '">'
            . '<button type="submit">Revoke key</button></form>';
    }

    /** @param list<string> $items */
    private static function listing(array $items): string
    {
        return $items === [] ? '<p>No records.</p>' : '<ul>' . implode('', $items) . '</ul>';
    }

    private static function csrf(string $token): string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Invalid CSRF token.');
        }
        return self::escape($token);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function page(string $title, string $content): string
    {
        $title = self::escape($title);
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="no-referrer">
  <meta name="robots" content="noindex, nofollow">
  <title>{$title} | OPGateway</title>
  <style>
    body { font: 16px system-ui,sans-serif; line-height: 1.5; background: #f6f8fa; color: #17202a; padding: 2rem 1rem; margin: 0; }
    main { max-width: 46rem; margin: auto; background: #fff; padding: 2rem; border: 1px solid #d7dfe6; border-radius: 10px; }
    label { display: block; margin-top: .5rem; font-weight: 600; }
    input:not([type="hidden"]) { display: block; padding: .5rem; max-width: 100%; box-sizing: border-box; }
    button { padding: .55rem .75rem; margin: .5rem 0; cursor: pointer; }
    section { border-top: 1px solid #ddd; margin: 1rem 0; overflow-wrap: anywhere; }
    li { margin: 1rem 0; }
    .inline { display: inline-block; margin-left: .5rem; }
    .secret { overflow-wrap: anywhere; padding: 1rem; background: #f1f4f7; border: 1px solid #c8d0d9; }
    :focus-visible { outline: 3px solid #125ab5; outline-offset: 2px; }
  </style>
</head>
<body>
{$content}
</body>
</html>
HTML;
    }
}
