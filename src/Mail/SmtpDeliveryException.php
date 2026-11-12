<?php

declare(strict_types=1);

namespace OPGateway\Mail;

use RuntimeException;

/** A validated SMTP configuration could not deliver the message. */
final class SmtpDeliveryException extends RuntimeException
{
}
