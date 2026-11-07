<?php

declare(strict_types=1);

namespace OPGateway\Database\Migrations;

/**
 * Immutable schema change definition.
 *
 * Statements are reviewed, hardcoded SQL; never accept SQL from HTTP input.
 * MySQL DDL may implicitly commit, so each migration needs a tested recovery plan.
 */
interface Migration
{
    /** Stable migration identifier; do not rename after it has been applied. */
    public function version(): string;

    /** @return list<string> Ordered DDL statements. */
    public function statements(): array;
}
