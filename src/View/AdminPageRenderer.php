<?php

declare(strict_types=1);

namespace OPGateway\View;

/** Presentation-only contract; the legacy HTML renderer remains the default. */
interface AdminPageRenderer
{
    public function login(string $csrfToken, bool $showError = false): string;

    /**
     * @param array<string,mixed>|null $pending Already-authorized, bounded DB data.
     */
    public function dashboard(
        string $csrfToken,
        string $email,
        ?array $pending = null,
        bool $actionsEnabled = false,
    ): string;
}
