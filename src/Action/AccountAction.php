<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Infrastructure\Persistence\RecoveryCodeRepository;
use LiveScoreTracker\Infrastructure\Persistence\TrustedDeviceRepository;
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
        private readonly TrustedDeviceRepository $trustedDevices,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->responder->context->requireUser();
        $errors = [];

        $op = Responder::field($request, 'op');
        if ($request->getMethod() === 'POST' && $op === 'revoke_device') {
            $this->trustedDevices->revoke($user->id, (int) Responder::field($request, 'device'));
            $this->responder->audit('auth.device_untrusted');
            $this->responder->flash('success', 'account.device_revoked');

            return $this->responder->redirect($response, '/account');
        }
        if ($request->getMethod() === 'POST' && $op === 'revoke_all') {
            $this->trustedDevices->revokeAll($user->id);
            $this->responder->audit('auth.device_untrusted', ['all' => true]);
            $this->responder->flash('success', 'account.devices_revoked');

            return $this->responder->redirect($response, '/account');
        }

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
            'trustedDevices' => $this->trustedDevices->forUser($user->id),
            'currentDevice' => $this->responder->context->deviceId,
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }
}
