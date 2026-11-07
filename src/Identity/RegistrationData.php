<?php

declare(strict_types=1);

namespace OPGateway\Identity;

use InvalidArgumentException;

/**
 * Validated registration data. Passwords must never be logged, serialized,
 * embedded in URLs, or retained after the registration operation.
 */
final readonly class RegistrationData
{
    private function __construct(
        public string $email,
        private string $password,
    ) {
    }

    public static function fromStrings(string $email, string $password): self
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[^\x21-\x7E]/', $email) === 1) {
            throw new InvalidArgumentException('Invalid email address.');
        }

        // Conservative length limits prevent trivial denial of service and
        // unbounded hashing workload. Do not log the provided value.
        $length = strlen($password);
        if ($length < 12 || $length > 1024 || str_contains($password, "\0")) {
            throw new InvalidArgumentException('Invalid password length or content.');
        }

        return new self($email, $password);
    }

    /** @internal Only call when producing a one-way hash. */
    public function passwordForHashing(): string
    {
        return $this->password;
    }
}
