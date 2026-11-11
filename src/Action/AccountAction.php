<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Infrastructure\Persistence\RecoveryCodeRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "My account": role, password change, 2FA status and new recovery codes.
 */
final class AccountAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly RecoveryCodeRepository $recoveryCodes,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->responder->context->requireUser();
        $errors = [];

        if ($request->getMethod() === 'POST' && $user->totpEnabled) {
            // New recovery codes: confirm with the password, then show them once.
            if ($user->verifyPassword(Responder::field($request, 'current_password', trim: false))) {
                $codes = $this->recoveryCodes->regenerate($user->id);
                $this->responder->audit('auth.recovery_codes_regenerated');

                return $this->responder->render($response, 'auth/recovery-codes.html.twig', ['codes' => $codes, 'next' => '/account']);
            }
            $errors['password.current_wrong'] = [];
        }

        return $this->responder->render($response, 'account.html.twig', [
            'remainingCodes' => $user->totpEnabled ? $this->recoveryCodes->remaining($user->id) : null,
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }
}
