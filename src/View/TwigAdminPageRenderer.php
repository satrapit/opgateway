<?php

declare(strict_types=1);

namespace OPGateway\View;

use InvalidArgumentException;

/**
 * TailAdmin Free visual language with Twig HTML autoescaping.
 *
 * Does not fetch data, grant permissions or generate CSRF values. Accepts
 * existing authorized administrator view models and their established
 * strict server-side form and mutation contracts.
 */
final readonly class TwigAdminPageRenderer implements AdminPageRenderer
{
    public function __construct(private TwigRenderer $twig)
    {
    }

    public function login(string $csrfToken, bool $showError = false): string
    {
        self::validateCsrf($csrfToken);

        return $this->twig->render('admin/login.html.twig', [
            'csrf_token' => $csrfToken,
            'show_error' => $showError,
        ]);
    }

    public function dashboard(
        string $csrfToken,
        string $email,
        ?array $pending = null,
        bool $actionsEnabled = false,
    ): string {
        return $this->section('overview', $csrfToken, $email, $pending, $actionsEnabled);
    }

    /**
     * Only predeclared page keys can be rendered; never interpolate URL input
     * into a Twig file name.
     *
     * @param array<string,mixed>|null $pending
     */
    public function section(
        string $section,
        string $csrfToken,
        string $email,
        ?array $pending = null,
        bool $actionsEnabled = false,
    ): string {
        $titles = [
            'overview' => 'Overview',
            'users' => 'User approvals',
            'owners' => 'Account owners',
            'clients' => 'API clients',
            'pending_ips' => 'Pending IP requests',
            'approved_ips' => 'Approved IPs',
        ];
        if (!array_key_exists($section, $titles)) {
            throw new InvalidArgumentException('Unknown admin section.');
        }

        self::validateCsrf($csrfToken);
        if (strlen($email) > 254) {
            throw new InvalidArgumentException('Invalid display value.');
        }

        // The five groups come from the existing read-only bounded queries;
        // every metric explicitly represents *visible* rows, not global totals.
        $groups = [
            'users' => $pending['users'] ?? [],
            'owners' => $pending['owners'] ?? [],
            'clients' => $pending['clients'] ?? [],
            'ips' => $pending['ips'] ?? [],
            'approved_ips' => $pending['approved_ips'] ?? [],
        ];

        return $this->twig->render('admin/dashboard.html.twig', [
            'active_section' => $section,
            'section_title' => $titles[$section],
            'csrf_token' => $csrfToken,
            'administrator_label' => $email,
            'actions_enabled' => $actionsEnabled,
            'review_available' => $pending !== null,
            'queues' => $groups,
            'visible_counts' => [
                'pending_users' => count($groups['users']),
                'owners' => count($groups['owners']),
                'clients' => count($groups['clients']),
                'pending_ips' => count($groups['ips']),
                'approved_ips' => count($groups['approved_ips']),
            ],
        ]);
    }

    private static function validateCsrf(string $token): void
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Invalid CSRF token.');
        }
    }
}
