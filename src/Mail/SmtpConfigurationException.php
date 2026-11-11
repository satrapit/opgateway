<?php

declare(strict_types=1);

namespace OPGateway\Mail;

use RuntimeException;

/** SMTP settings or their private encrypted secret cannot be loaded safely. */
final class SmtpConfigurationException extends RuntimeException
{
}
