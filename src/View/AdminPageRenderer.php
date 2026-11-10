<?php

declare(strict_types=1);

namespace OPGateway\View;

/** Presentation-only contract for the single administrator Twig UI. */
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

    /**
     * Render one allowlisted administrative workspace page.
     * The data shape is identical to dashboard(); only presentation changes.
     *
     * @param array<string,mixed>|null $pending Already-authorized, bounded DB data.
     */
    public function section(
        string $section,
        string $csrfToken,
        string $email,
        ?array $pending = null,
        bool $actionsEnabled = false,
    ): string;
}
