<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Mail;

use LiveScoreTracker\Infrastructure\Settings;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends emails through the hosting's SMTP server (spec § 20.1).
 */
final class SmtpMailer implements Mailer
{
    public function __construct(private readonly Settings $settings) {}

    public function send(string $to, string $subject, string $body): void
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->settings->mailHost;
            $mail->Port = $this->settings->mailPort;
            $mail->SMTPAuth = $this->settings->mailUser !== '';
            $mail->Username = $this->settings->mailUser;
            $mail->Password = $this->settings->mailPassword;
            $mail->SMTPSecure = $this->settings->mailEncryption === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Timeout = 15;
            $mail->setFrom($this->settings->mailFrom, $this->settings->mailFromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(false);
            $mail->send();
        } catch (PHPMailerException $exception) {
            throw new MailFailed($exception->getMessage(), 0, $exception);
        }
    }
}
