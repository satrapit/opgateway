<?php

declare(strict_types=1);

namespace OPGateway\Tests;

use OPGateway\Database\Migrations\AddUserNames;
use OPGateway\Database\Migrations\MigrationRunner;
use OPGateway\Http\AccountViews;
use OPGateway\Identity\PdoUserProfile;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class UserProfilePolicyTest extends TestCase
{
    public function testAdditiveMigrationOnlyAddsNullableNames(): void
    {
        $migration = new AddUserNames();
        self::assertSame('202611120002_add_user_names', $migration->version());
        self::assertCount(1, $migration->statements());
        self::assertStringContainsString('ALTER TABLE users', $migration->statements()[0]);
        self::assertStringContainsString('first_name VARCHAR(100) NULL', $migration->statements()[0]);
        self::assertStringContainsString('last_name VARCHAR(100) NULL', $migration->statements()[0]);
        $sql = file_get_contents(dirname(__DIR__) . '/database/sql/upgrade_202611120002.sql');
        self::assertIsString($sql);
        self::assertStringContainsString(MigrationRunner::checksum($migration), $sql);
        self::assertStringContainsString($migration->statements()[0] . ';', $sql);
    }

    public function testProfileNameValidationAcceptsUnicodeButRejectsControls(): void
    {
        self::assertSame('مجید', PdoUserProfile::cleanName(' مجید '));
        self::assertSame('A', PdoUserProfile::cleanName(' A '));
        self::assertNull(PdoUserProfile::cleanName('   '));
        self::assertNull(PdoUserProfile::cleanName('<script>'));
        self::assertNull(PdoUserProfile::cleanName("A\nB"));
        self::assertNull(PdoUserProfile::cleanName(str_repeat('x', 101)));
        self::assertSame(str_repeat('آ', 100), PdoUserProfile::cleanName(str_repeat('آ', 100)));
    }

    public function testProfileFormRequiresCsrfAndBothNames(): void
    {
        $html = AccountViews::profile(str_repeat('a', 64), [
            'first_name' => '', 'last_name' => '', 'complete' => false,
        ]);
        self::assertStringContainsString('action="/account/profile"', $html);
        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('name="first_name"', $html);
        self::assertStringContainsString('name="last_name"', $html);
        self::assertStringContainsString('Complete your profile', $html);
    }

    public function testProfileRepositoryChecksEligibilityAndSavesOnlyOwnRow(): void
    {
        $pdo = new class extends PDO {
            public string $first = '';
            public string $last = '';
            public bool $eligible = true;
            public string $query = '';
            public array $parameters = [];

            public function __construct() {}

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $this->query = $query;
                return new class($this, $query) extends PDOStatement {
                    private int $updated = 0;
                    public function __construct(private PDO $db, private string $sql) {}

                    public function execute(?array $params = null): bool
                    {
                        $this->db->parameters = $params ?? [];
                        if (str_starts_with($this->sql, 'UPDATE users')) {
                            $this->updated = $this->db->eligible ? 1 : 0;
                            if ($this->updated) {
                                $this->db->first = (string) $params[0];
                                $this->db->last = (string) $params[1];
                            }
                        }
                        return true;
                    }

                    public function fetch(int $mode = PDO::FETCH_DEFAULT,
                        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
                        int $cursorOffset = 0): mixed
                    {
                        return $this->db->eligible
                            ? ['first_name' => $this->db->first, 'last_name' => $this->db->last]
                            : false;
                    }

                    public function rowCount(): int
                    {
                        return $this->updated;
                    }
                };
            }
        };
        $profile = new PdoUserProfile($pdo);
        self::assertFalse($profile->complete(7));
        self::assertTrue($profile->save(7, 'Majid', 'Barkhordari'));
        self::assertSame([7], $profile->forUser(7) === null ? [] : [7]);
        self::assertTrue($profile->complete(7));
        self::assertSame('Majid', $profile->forUser(7)['first_name']);
        self::assertSame(7, $pdo->parameters[0]);
        $pdo->eligible = false;
        self::assertFalse($profile->save(7, 'Unknown', 'User'));
        self::assertNull($profile->forUser(7));
    }
}
