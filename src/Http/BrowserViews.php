<?php

declare(strict_types=1);

namespace OPGateway\Http;

use InvalidArgumentException;
use OPGateway\View\TwigRenderer;

/**
 * Admin view facade. Preserve the existing adapter contract while migrating
 * presentation-only HTML to escaped, private Twig templates.
 */
final class BrowserViews
{
    public static function login(string $csrfToken, bool $showError = false): string
    {
        self::assertCsrf($csrfToken);

        return TwigRenderer::render('admin/login.html.twig', [
            'csrf_token' => $csrfToken,
            'show_error' => $showError,
        ]);
    }

    /**
     * @param array{
     *   users:list<array{id:int,email:string}>,
     *   owners?:list<array{id:int,email:string}>,
     *   clients:list<array{id:int,label:string,status?:string,owner_email:string}>,
     *   ips:list<array{id:int,client_label:string,address:string}>,
     *   approved_ips?:list<array{id:int,client_label:string,address:string}>
     * }|null $pending
     */
    public static function dashboard(
        string $csrfToken,
        string $email,
        ?array $pending = null,
        bool $actionsEnabled = false,
    ): string {
        self::assertCsrf($csrfToken);
        if (strlen($email) > 254) {
            throw new InvalidArgumentException('Invalid display value.');
        }

        return TwigRenderer::render('admin/dashboard.html.twig', [
            'csrf_token' => $csrfToken,
            'display_email' => $email,
            'has_overview' => $pending !== null,
            'show_approved_ips' => $pending !== null && array_key_exists('approved_ips', $pending),
            'actions_enabled' => $pending !== null && $actionsEnabled,
            'queues' => [
                'users' => $pending['users'] ?? [],
                'owners' => $pending['owners'] ?? [],
                'clients' => $pending['clients'] ?? [],
                'ips' => $pending['ips'] ?? [],
                'approved_ips' => $pending['approved_ips'] ?? [],
            ],
        ]);
    }

    private static function assertCsrf(string $token): void
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Invalid CSRF token.');
        }
    }
}
