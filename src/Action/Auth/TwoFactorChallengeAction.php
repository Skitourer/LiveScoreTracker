<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Auth;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Http\Middleware\SessionMiddleware;
use LiveScoreTracker\Infrastructure\Persistence\RecoveryCodeRepository;
use LiveScoreTracker\Infrastructure\Persistence\Throttle;
use LiveScoreTracker\Infrastructure\Persistence\TrustedDeviceRepository;
use LiveScoreTracker\Infrastructure\Security\TwoFactor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Second login step for admins: a code from the authenticator app,
 * or one of the recovery codes (spec § 19.1).
 */
final class TwoFactorChallengeAction
{
    private const MAX_FAILURES = 5;

    public function __construct(
        private readonly Responder $responder,
        private readonly TwoFactor $twoFactor,
        private readonly RecoveryCodeRepository $recoveryCodes,
        private readonly Throttle $throttle,
        private readonly TrustedDeviceRepository $trustedDevices,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = $this->responder->context;
        $user = $context->requireUser();

        if ($request->getMethod() !== 'POST') {
            return $this->form($response, []);
        }

        $key = '2fa:' . $user->id;
        $blocked = $this->throttle->secondsBlocked($key);
        if ($blocked > 0) {
            return $this->form($response, ['login.blocked' => ['minutes' => Responder::minutes($blocked)]], 429);
        }

        $code = Responder::field($request, 'code');
        $usedRecoveryCode = false;
        if (RecoveryCodeRepository::looksLikeRecoveryCode($code)) {
            $valid = $usedRecoveryCode = $this->recoveryCodes->consume($user->id, $code);
        } else {
            $valid = $user->totpSecret !== null && $this->twoFactor->verify($user->totpSecret, $code);
        }

        if (!$valid) {
            $this->throttle->recordFailure($key, self::MAX_FAILURES);
            $this->responder->audit('auth.2fa_failed');

            return $this->form($response, ['two_factor.invalid_code' => []], 401);
        }

        $this->throttle->clear($key);
        $context->session()->completeSecondFactor();
        $this->responder->audit('auth.login', ['second_factor' => $usedRecoveryCode ? 'recovery_code' : 'totp']);
        if ($usedRecoveryCode) {
            $this->responder->flash('warning', 'two_factor.recovery_code_used', ['remaining' => $this->recoveryCodes->remaining($user->id)]);
        }

        $response = $this->responder->redirect($response, LoginAction::takeNext($context->session()));
        if (Responder::field($request, 'trust_device') === '1') {
            $token = $this->trustedDevices->trust($user->id, $context->deviceId, $request->getHeaderLine('User-Agent') ?: null);
            $this->responder->audit('auth.device_trusted', ['days' => TrustedDeviceRepository::LIFETIME_DAYS]);
            $response = $response->withAddedHeader('Set-Cookie', SessionMiddleware::cookie(
                SessionMiddleware::TRUST_COOKIE,
                $token,
                TrustedDeviceRepository::LIFETIME_DAYS * 86400,
                SessionMiddleware::isHttps($request),
            ));
        }

        return $response;
    }

    /**
     * @param array<string, array<string, int>> $errors
     */
    private function form(ResponseInterface $response, array $errors, int $status = 200): ResponseInterface
    {
        return $this->responder->render($response, 'auth/two-factor.html.twig', ['errors' => $errors], $status);
    }
}
