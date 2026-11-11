<?php

declare(strict_types=1);

namespace OPGateway\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use OPGateway\Identity\PasswordResetMailer;
use RuntimeException;
use Throwable;

/** SMTP verification and reset mail with peer TLS verification and no debug output. */
final readonly class PhpMailerVerificationMailer implements VerificationMailer, PasswordResetMailer, InvitationMailer
{
    public function __construct(private PdoSmtpSettings $settings)
    {
    }

    public function sendVerification(string $email, string $token, string $appUrl): void
    {
        $this->validateLink($email, $token, $appUrl);
        $this->send($email, 'Verify your OPGateway email address',
            "Verify your email using this one-time link:\n" . $appUrl . '/account/verify?token=' . $token
            . "\n\nThe link expires in 30 minutes.\nIf you did not register, ignore this message.");
    }

    public function sendInvitation(string $email, string $token, string $origin): void
    {
        $this->validateLink($email, $token, $origin);
        $this->send($email, 'Your OPGateway invitation',
            "An administrator invited you to OPGateway. Confirm your email:\n"
            . $origin . '/account/verify?token=' . $token
            . "\n\nThis link expires in 30 minutes. An administrator must approve"
            . " your account after verification. Once approved, use"
            . " Forgot password to set your own password.\n"
            . "If you did not expect this invitation, ignore this message.");
    }

    public function sendPasswordReset(string $email, string $token, string $origin): void
    {
        $this->validateLink($email, $token, $origin);
        $this->send($email, 'Reset your OPGateway password',
            "To choose a new password, open this one-time link:\n"
            . $origin . '/account/reset-password?token=' . $token
            . "\n\nThe link expires in 15 minutes.\nIf you did not request this, ignore this message.");
    }

    private function validateLink(string $email, string $token, string $origin): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
            || preg_match('~^https://[a-z0-9.-]+(?::[0-9]{1,5})?$~iD', $origin) !== 1
            || !class_exists(PHPMailer::class)) {
            throw new RuntimeException('SMTP delivery is unavailable.');
        }
    }

    private function send(string $email, string $subject, string $message): void
    {
        $config = $this->settings->connection();
        if ($config === null) {
            throw new RuntimeException('SMTP is not configured.');
        }
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
            $mailer->Subject = $subject;
            $mailer->Body = $message;
            $mailer->isHTML(false);
            if (!$mailer->send()) {
                throw new RuntimeException('SMTP delivery failed.');
            }
        } catch (Throwable $error) {
            throw new RuntimeException('SMTP delivery failed.', 0, $error);
        } finally {
            $mailer->smtpClose();
            $mailer->Password = '';
        }
    }
}
