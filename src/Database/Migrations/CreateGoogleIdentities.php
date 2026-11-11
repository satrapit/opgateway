<?php

declare(strict_types=1);

namespace OPGateway\Database\Migrations;

/** Additive migration 007: bind stable Google OIDC subject to an existing user. */
final class CreateGoogleIdentities implements Migration
{
    public function version(): string
    {
        return '202611110001_create_google_identities';
    }

    /** @return list<string> */
    public function statements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE google_identities (
    user_id BIGINT UNSIGNED NOT NULL,
    google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    google_email VARCHAR(254) NOT NULL,
    linked_at DATETIME(6) NOT NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_google_identities_sub (google_sub),
    CONSTRAINT fk_google_identities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }
}
