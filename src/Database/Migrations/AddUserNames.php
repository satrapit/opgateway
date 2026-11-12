<?php

declare(strict_types=1);

namespace OPGateway\Database\Migrations;

/** Add optional profile names to the existing shared users identity table. */
final class AddUserNames implements Migration
{
    public function version(): string
    {
        return '202611120002_add_user_names';
    }

    /** @return list<string> */
    public function statements(): array
    {
        return [
            <<<'SQL'
ALTER TABLE users
ADD first_name VARCHAR(100) NULL,
ADD last_name VARCHAR(100) NULL
SQL,
        ];
    }
}
