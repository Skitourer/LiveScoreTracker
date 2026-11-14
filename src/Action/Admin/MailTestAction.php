<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Admin;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Registration\TextCleaner;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use LiveScoreTracker\Infrastructure\Mail\Mailer;
use LiveScoreTracker\Infrastructure\Mail\MailFailed;
use LiveScoreTracker\Infrastructure\Mail\SmtpMailer;
use LiveScoreTracker\Infrastructure\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Test email (admins): sends a short message to an address and shows the
 * dialogue with the SMTP server, to find out why emails do not arrive.
 */
final class MailTestAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Mailer $mailer,
        private readonly Settings $settings,
        private readonly Translator $translator,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $to = $this->responder->context->user->email ?? '';
        $result = null;

        if ($request->getMethod() === 'POST') {
            $to = TextCleaner::email(Responder::field($request, 'to'));
            if (!TextCleaner::isEmail($to)) {
                $result = ['sent' => false, 'error' => $this->translator->trans('registration.email_invalid', ['value' => $to]), 'transcript' => []];
            } else {
                $subject = $this->translator->trans('mail_test.subject');
                $body = $this->translator->trans('mail_test.body', ['date' => gmdate('Y-m-d H:i:s') . ' UTC']);
                if ($this->mailer instanceof SmtpMailer) {
                    try {
                        $result = $this->mailer->diagnose($to, $subject, $body);
                    } catch (MailFailed $failure) {
                        $result = ['sent' => false, 'error' => $failure->getMessage(), 'transcript' => []];
                    }
                } else {
                    try {
                        $this->mailer->send($to, $subject, $body);
                        $result = ['sent' => true, 'error' => null, 'transcript' => []];
                    } catch (MailFailed $failure) {
                        $result = ['sent' => false, 'error' => $failure->getMessage(), 'transcript' => []];
                    }
                }
                $this->responder->audit('mail.test', ['to' => $to, 'sent' => $result['sent'] ? 'yes' : 'no']);
            }
        }

        return $this->responder->render($response, 'admin/mail-test.html.twig', [
            'to' => $to,
            'result' => $result,
            'smtp' => $this->settings->mailTransport === 'smtp',
            'mail' => [
                'host' => $this->settings->mailHost,
                'port' => $this->settings->mailPort,
                'encryption' => $this->settings->mailEncryption,
                'from' => $this->settings->mailFrom,
                'auth' => $this->settings->mailUser !== '',
                'same_sender' => $this->settings->mailUser === '' || strcasecmp($this->settings->mailUser, $this->settings->mailFrom) === 0,
            ],
        ]);
    }
}
