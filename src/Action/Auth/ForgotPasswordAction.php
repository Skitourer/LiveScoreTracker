<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Auth;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use LiveScoreTracker\Infrastructure\Mail\Mailer;
use LiveScoreTracker\Infrastructure\Mail\MailFailed;
use LiveScoreTracker\Infrastructure\Persistence\PasswordResetRepository;
use LiveScoreTracker\Infrastructure\Persistence\Throttle;
use LiveScoreTracker\Infrastructure\Persistence\UserRepository;
use LiveScoreTracker\Infrastructure\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "Forgot password" for admins and organizers: a single-use link valid one hour,
 * sent by email (spec § 19.1).
 *
 * The answer is the same whether or not the address exists, so the page
 * cannot be used to find out who has an account.
 */
final class ForgotPasswordAction
{
    private const MAX_REQUESTS = 5;

    public function __construct(
        private readonly Responder $responder,
        private readonly UserRepository $users,
        private readonly PasswordResetRepository $resets,
        private readonly Throttle $throttle,
        private readonly Mailer $mailer,
        private readonly Translator $translator,
        private readonly Settings $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->responder->render($response, 'auth/forgot-password.html.twig', ['sent' => false]);
        }

        $email = Responder::field($request, 'email');
        $ipKey = 'forgot-ip:' . ($this->responder->context->ip ?? 'unknown');

        if ($this->throttle->secondsBlocked($ipKey) === 0 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            // Every request counts, successful or not: this limits emails sent to one address.
            $this->throttle->recordFailure($ipKey, self::MAX_REQUESTS);
            $user = $this->users->findByEmail($email);
            if ($user !== null && !$user->disabled && $user->email !== null) {
                $token = $this->resets->create($user->id);
                $link = $this->baseUrl($request) . '/password/reset/' . $token;
                try {
                    $this->mailer->send(
                        $user->email,
                        $this->translator->trans('email.password_reset.subject', ['app' => $this->translator->trans('app.name')]),
                        $this->translator->trans('email.password_reset.body', ['name' => $user->displayName, 'link' => $link]),
                    );
                    $this->responder->audit('auth.password_reset_requested', [], $user);
                } catch (MailFailed $exception) {
                    $this->responder->audit('auth.password_reset_mail_failed', ['error' => mb_substr($exception->getMessage(), 0, 200)], $user);
                }
            }
        }

        return $this->responder->render($response, 'auth/forgot-password.html.twig', ['sent' => true]);
    }

    private function baseUrl(ServerRequestInterface $request): string
    {
        if ($this->settings->appUrl !== '') {
            return $this->settings->appUrl;
        }
        $uri = $request->getUri();

        return $uri->getScheme() . '://' . $uri->getAuthority();
    }
}
