<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Identity\PdoEmailVerificationRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class PdoEmailVerificationRepositoryTest extends TestCase
{
    public function testEligibleSelfServiceVerificationActivatesAndConsumesAtomically(): void
    {
        $db = self::database();
        $repository = new PdoEmailVerificationRepository($db, true);

        self::assertTrue($repository->consume(str_repeat('a', 64)));
        self::assertSame('active', $db->status);
        self::assertTrue($db->verified);
        self::assertTrue($db->approved);
        self::assertTrue($db->tokenConsumed);
        self::assertFalse($db->transaction);
    }

    public function testExpiredTokenIsRejectedWithoutChangingAccount(): void
    {
        $db = self::database();
        $db->expired = true;
        $repository = new PdoEmailVerificationRepository($db, true);

        self::assertFalse($repository->consume(str_repeat('b', 64)));
        self::assertSame('pending', $db->status);
        self::assertFalse($db->verified);
        self::assertFalse($db->approved);
        self::assertFalse($db->tokenConsumed);
        self::assertFalse($db->transaction);
    }

    public function testSuspendedAccountCannotBeVerifiedOrConsumeToken(): void
    {
        $db = self::database();
        $db->status = 'disabled';
        $repository = new PdoEmailVerificationRepository($db, true);

        self::assertFalse($repository->consume(str_repeat('c', 64)));
        self::assertSame('disabled', $db->status);
        self::assertFalse($db->verified);
        self::assertFalse($db->approved);
        self::assertFalse($db->tokenConsumed);
        self::assertFalse($db->transaction);
    }

    private static function database(): PDO
    {
        return new class extends PDO {
            public bool $transaction = false;
            public bool $expired = false;
            public bool $tokenConsumed = false;
            public string $status = 'pending';
            public string $signupSource = 'self_service';
            public bool $verified = false;
            public bool $approved = false;

            public function __construct()
            {
            }

            public function beginTransaction(): bool
            {
                $this->transaction = true;
                return true;
            }

            public function commit(): bool
            {
                $this->transaction = false;
                return true;
            }

            public function rollBack(): bool
            {
                $this->transaction = false;
                return true;
            }

            public function inTransaction(): bool
            {
                return $this->transaction;
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return new class($this, $query) extends PDOStatement {
                    private int $rows = 0;

                    public function __construct(private PDO $db, private string $sql)
                    {
                    }

                    public function execute(?array $params = null): bool
                    {
                        if (str_starts_with(trim($this->sql), 'UPDATE users')) {
                            if ($this->db->status !== 'pending' || $this->db->verified) {
                                $this->rows = 0;
                                return true;
                            }
                            $this->db->verified = true;
                            if (str_contains($this->sql, "signup_source = 'self_service'")
                                && $this->db->signupSource === 'self_service') {
                                $this->db->approved = true;
                                $this->db->status = 'active';
                            }
                            $this->rows = 1;
                            return true;
                        }
                        if (str_starts_with(trim($this->sql),
                            'UPDATE email_verification_tokens')
                            && str_contains($this->sql,
                            'WHERE token_hash = ? AND consumed_at IS NULL')) {
                            $this->rows = $this->db->tokenConsumed ? 0 : 1;
                            if ($this->rows === 1) {
                                $this->db->tokenConsumed = true;
                            }
                            return true;
                        }
                        $this->rows = 1;
                        return true;
                    }

                    public function fetchColumn(int $column = 0): mixed
                    {
                        return !$this->db->expired && !$this->db->tokenConsumed ? 17 : false;
                    }

                    public function rowCount(): int
                    {
                        return $this->rows;
                    }
                };
            }
        };
    }
}
