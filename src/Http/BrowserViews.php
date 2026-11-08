<?php

declare(strict_types=1);

namespace OPGateway\Http;

use InvalidArgumentException;

/**
 * Dependency-free HTML views for a future authenticated browser gateway.
 *
 * Outputs are encoded for HTML contexts. No remote scripts, stylesheets,
 * frameworks, trackers or inline JavaScript are loaded.
 */
final class BrowserViews
{
    public static function login(string $csrfToken, bool $showError = false): string
    {
        self::assertCsrf($csrfToken);
        $token = self::escape($csrfToken);
        $error = $showError
            ? '<p role="alert">Sign-in was not successful.</p>'
            : '';
        $content = <<<HTML
<main>
  <h1>OPGateway</h1>
  <p>Administrator sign in</p>
  {$error}
  <form method="post" action="/admin/login" autocomplete="on">
    <input type="hidden" name="_csrf" value="{$token}">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" maxlength="254" autocomplete="username" required>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <button type="submit">Sign in</button>
  </form>
</main>
HTML;
        return self::page('Administrator sign in', $content);
    }

    public static function dashboard(string $csrfToken, string $email): string
    {
        self::assertCsrf($csrfToken);
        if (strlen($email) > 254) {
            throw new InvalidArgumentException('Invalid display value.');
        }
        $token = self::escape($csrfToken);
        $displayEmail = self::escape($email);
        $content = <<<HTML
<main>
  <h1>OPGateway administration</h1>
  <p>Signed in as {$displayEmail}</p>
  <p>Management features are not available until production security checks pass.</p>
  <form method="post" action="/admin/logout">
    <input type="hidden" name="_csrf" value="{$token}">
    <button type="submit">Sign out</button>
  </form>
</main>
HTML;
        return self::page('Administration', $content);
    }

    private static function assertCsrf(string $token): void
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Invalid CSRF token.');
        }
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function page(string $title, string $body): string
    {
        $safeTitle = self::escape($title);
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="same-origin">
  <title>{$safeTitle} | OPGateway</title>
  <style>
    body { font: 16px system-ui, sans-serif; line-height: 1.55; margin: 0; padding: 3rem 1rem; background: #f6f8fa; color: #17202a; }
    main { max-width: 28rem; margin: 0 auto; padding: 2rem; background: white; border: 1px solid #d7dfe6; border-radius: 12px; }
    label { display: block; margin-top: 1rem; font-weight: 600; }
    input:not([type="hidden"]) { display: block; box-sizing: border-box; width: 100%; padding: .75rem; font: inherit; }
    button { margin-top: 1.5rem; padding: .7rem 1rem; font: inherit; cursor: pointer; }
    :focus-visible { outline: 3px solid #125ab5; outline-offset: 2px; }
  </style>
</head>
<body>
{$body}
</body>
</html>
HTML;
    }
}
