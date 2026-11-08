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
    public static function login(string $csrfToken, bool $failed = false): string
    {
        $csrf = self::csrf($csrfToken);
        $notice = $failed ? '<p role="alert">Sign-in was not successful.</p>' : '';
        $body = <<<HTML
<main>
  <h1>OPGateway account</h1>
  <p>Sign in with your approved account.</p>
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

    /**
     * @param array{
     *   email:string,
     *   clients:list<array{id:int,label:string,status:string}>,
     *   credentials:list<array{id:int,client_id:int,prefix:string,revoked:bool}>,
     *   ips:list<array{id:int,client_id:int,address:string,enabled:bool}>
     * } $overview
     */
    public static function dashboard(string $csrfToken, array $overview, bool $keysEnabled): string
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

        $body = <<<HTML
<main>
  <h1>OPGateway account</h1>
  <p>Signed in as {$email}</p>
  <p>{$state}</p>
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
