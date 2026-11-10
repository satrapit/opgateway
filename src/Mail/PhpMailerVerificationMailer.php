<?php

declare(strict_types=1);

namespace OPGateway\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use OPGateway\Identity\EmailVerificationToken;
use RuntimeException;
use Throwable;

/** Standards-based SMTP with strict peer verification and no debug output. */
final readonly class PhpMailerVerificationMailer implements VerificationMailer
{
    public function __construct(private PdoSmtpSettings $settings)
    {
    }

    public function sendVerification(string $email, string $token, string $appUrl): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || !preg_match('/^[a-f0-9]{64}$/D', $token)
            || preg_match('~^https://[a-z0-9.-]+(?::[0-9]{1,5})?$~iD', $appUrl) !== 1
            || !class_exists(PHPMailer::class)) {
            throw new RuntimeException('Secure SMTP delivery is unavailable.');
        }
        $config = $this->settings->connection();
        if ($config === null) {
            throw new RuntimeException('SMTP is not configured.');
        }

        $link = $appUrl . '/account/verify?token=' . $token;
        $mailer = new PHPMailer(true);
        try {
            $mailer->isSMTP();
            $mailer->Host = $config->host;
            $mailer->Port = $config->port;
            $mailer->SMTPSecure = $config->encryption === 'smtps'
                ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->SMTPAutoTLS = true;
            $mailer->SMTPAuth = true;
            $mailer->Username = $config->username;
            $mailer->Password = $config->password;
            $mailer->Timeout = 10;
            $mailer->SMTPDebug = 0;
            $mailer->SMTPOptions = ['ssl' => [
                'verify_peer' => true, 'verify_peer_name' => true,
                'allow_self_signed' => false, 'peer_name' => $config->host,
            ]];
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->setFrom($config->fromEmail, $config->fromName);
            $mailer->addAddress($email);
            $mailer->Subject = 'Verify your OPGateway email address';
            $mailer->Body = "Verify your email using this one-time link:\n"
                . $link . "\n\nThe link expires in 30 minutes."
                . "\nIf you did not register, ignore this message.";
            $mailer->isHTML(false);
            if (!$mailer->send()) {
                throw new RuntimeException('SMTP delivery failed.');
            }
        } catch (Throwable $exception) {
            // PHPMailer exceptions may contain host/user/address details.
            // Never disclose the exception or its message to public clients.
            throw new RuntimeException('SMTP delivery failed.', 0, $exception);
        } finally {
            $mailer->smtpClose();
            $mailer->Password = '';
        }
    }
}
