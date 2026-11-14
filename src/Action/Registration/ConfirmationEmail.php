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
        $email = ['email' => $registration->child->guardianEmail];
        if (!$sent) {
            $responder->audit('registration.email_failed', ['bib' => $registration->bib, 'error' => $mailer->lastError]);
            $responder->flash('warning', 'registration.email_failed', $email);
        } elseif (!$mailer->delivers()) {
            // Written to the mail log only: saying "sent" would be misleading.
            $responder->audit('registration.email_logged', ['bib' => $registration->bib]);
            $responder->flash('warning', 'registration.email_logged', $email);
        } else {
            $responder->audit('registration.email_sent', ['bib' => $registration->bib]);
            $responder->flash('success', 'registration.email_sent', $email);
        }
    }
}
