<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Registration;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\RegistrationMailer;
use LiveScoreTracker\Domain\Registration\Registration;

/**
 * Sends the confirmation email from a screen, with a message and an audit entry.
 */
final class ConfirmationEmail
{
    public static function send(RegistrationMailer $mailer, Responder $responder, Registration $registration): void
    {
        $sent = $mailer->sendConfirmation($registration);
        $responder->audit($sent ? 'registration.email_sent' : 'registration.email_failed', ['bib' => $registration->bib]);
        $responder->flash(
            $sent ? 'success' : 'warning',
            $sent ? 'registration.email_sent' : 'registration.email_failed',
            ['email' => $registration->child->guardianEmail],
        );
    }
}
