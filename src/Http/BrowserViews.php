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

    /**
     * @param array{
     *   users:list<array{id:int,email:string}>,
     *   clients:list<array{id:int,label:string,owner_email:string}>,
     *   ips:list<array{id:int,client_label:string,address:string}>
     * }|null $pending
     */
    public static function dashboard(
        string $csrfToken,
        string $email,
        ?array $pending = null,
        bool $actionsEnabled = false,
    ): string
    {
        self::assertCsrf($csrfToken);
        if (strlen($email) > 254) {
            throw new InvalidArgumentException('Invalid display value.');
        }
        $token = self::escape($csrfToken);
        $displayEmail = self::escape($email);
        $overview = $pending === null
            ? ''
            : self::pendingLists($pending, $token, $actionsEnabled);
        $reviewMessage = $pending === null
            ? 'Management features are not yet configured.'
            : ($actionsEnabled ? 'Review actions are enabled.' : 'Review queues are read-only.');
        $content = <<<HTML
<main>
  <h1>OPGateway administration</h1>
  <p>Signed in as {$displayEmail}</p>
  <p>{$reviewMessage}</p>
  {$overview}
  <form method="post" action="/admin/logout">
    <input type="hidden" name="_csrf" value="{$token}">
    <button type="submit">Sign out</button>
  </form>
</main>
HTML;
        return self::page('Administration', $content);
    }


    /**
     * Render at most 50 entries per queue; no client credential or secret
     * is ever read or reflected. Every database-originated value is escaped.
     *
     * @param array{
     *   users:list<array{id:int,email:string}>,
     *   clients:list<array{id:int,label:string,owner_email:string}>,
     *   ips:list<array{id:int,client_label:string,address:string}>
     * } $pending
     */
    private static function pendingLists(array $pending, string $token, bool $actionsEnabled): string
    {
        $users = [];
        foreach ($pending['users'] as $item) {
            $users[] = '<li>#' . (int) $item['id'] . ' — '
                . self::escape($item['email'])
                . ($actionsEnabled ? self::reviewForm($token, 'approve_user', (int) $item['id'], 'Approve user') : '')
                . '</li>';
        }

        $clients = [];
        foreach ($pending['clients'] as $item) {
            $clients[] = '<li>#' . (int) $item['id'] . ' — '
                . self::escape($item['label'])
                . ' (owner: ' . self::escape($item['owner_email']) . ')'
                . ($actionsEnabled ? self::reviewForm($token, 'activate_client', (int) $item['id'], 'Activate client') : '')
                . '</li>';
        }

        $ips = [];
        foreach ($pending['ips'] as $item) {
            $ips[] = '<li>#' . (int) $item['id'] . ' — '
                . self::escape($item['address']) . ' (client: '
                . self::escape($item['client_label']) . ')'
                . ($actionsEnabled ? self::reviewForm($token, 'approve_ip', (int) $item['id'], 'Approve IP') : '')
                . '</li>';
        }

        return self::listSection('Verified users awaiting approval', $users)
            . self::listSection('Inactive clients eligible for review', $clients)
            . self::listSection('Pending IP allowlist requests', $ips);
    }

    /** Form actions are selected only from hardcoded allowlisted values. */
    private static function reviewForm(string $token, string $action, int $targetId, string $label): string
    {
        return '<form class="review-form" method="post" action="/admin/review">'
            . '<input type="hidden" name="_csrf" value="' . $token . '">'
            . '<input type="hidden" name="action" value="' . self::escape($action) . '">'
            . '<input type="hidden" name="target_id" value="' . $targetId . '">'
            . '<button type="submit">' . self::escape($label) . '</button></form>';
    }

    /** @param list<string> $escapedItems */
    private static function listSection(string $heading, array $escapedItems): string
    {
        $items = $escapedItems === []
            ? '<p>None awaiting review.</p>'
            : '<ul>' . implode('', $escapedItems) . '</ul>';

        return '<section><h2>' . self::escape($heading) . '</h2>'
            . $items . '<p class="hint">Showing up to 50 records.</p></section>';
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
    section { border-top: 1px solid #d7dfe6; margin-top: 1.5rem; padding-top: .75rem; overflow-wrap: anywhere; }
    section h2 { font-size: 1.1rem; }
    .review-form { display: inline-block; margin-left: .75rem; }
    .review-form button { margin: .4rem 0; padding: .3rem .6rem; }
    .hint { font-size: .85rem; color: #596579; }
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
