# Testing — initial foundation

Execute `php scripts/lint.php` to syntax-check PHP files and `php scripts/smoke.php` for zero-dependency foundation checks. When Composer is installed locally, run `composer install` and `composer test` for the PHPUnit suite.

The smoke checks intentionally demonstrate that all proxied `/v1` routes remain unavailable even when a configuration flag is enabled. They do not prove real tenant isolation, end-to-end authentication, upstream connectivity, or production security.

Do not use real production provider credentials for automated tests.
