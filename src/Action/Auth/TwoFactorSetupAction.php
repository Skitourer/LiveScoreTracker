<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Auth;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Http\AuthState;
use LiveScoreTracker\Infrastructure\Config\ConfigRepository;
use LiveScoreTracker\Infrastructure\Persistence\RecoveryCodeRepository;
use LiveScoreTracker\Infrastructure\Persistence\UserRepository;
use LiveScoreTracker\Infrastructure\Security\TwoFactor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Two-factor activation, mandatory for admins (spec § 19.1).
 *
 * The secret stays in the session until the admin proves the app works by
 * typing a valid code; then it is saved and 10 recovery codes are shown once.
 */
final class TwoFactorSetupAction
{
    private const PENDING_SECRET = 'pending_totp_secret';

    public function __construct(
        private readonly Responder $responder,
        private readonly TwoFactor $twoFactor,
        private readonly UserRepository $users,
        private readonly RecoveryCodeRepository $recoveryCodes,
        private readonly ConfigRepository $config,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = $this->responder->context;
        $user = $context->requireUser();
        $session = $context->session();

        // An admin who already has 2FA comes here to see its status (account page).
        if ($user->totpEnabled && $context->state === AuthState::Authenticated) {
            return $this->responder->redirect($response, '/account');
        }

        $secret = $session->get(self::PENDING_SECRET);
        if (!\is_string($secret) || $secret === '') {
            $secret = $this->twoFactor->newSecret();
            $session->set(self::PENDING_SECRET, $secret);
        }

        $errors = [];
        if ($request->getMethod() === 'POST') {
            if ($this->twoFactor->verify($secret, Responder::field($request, 'code'))) {
                $this->users->setPendingTotpSecret($user->id, $secret);
                $this->users->enableTotp($user->id);
                $codes = $this->recoveryCodes->regenerate($user->id);
                $session->remove(self::PENDING_SECRET);
                $session->completeSecondFactor();
                $this->responder->audit('auth.2fa_enabled');

                return $this->responder->render($response, 'auth/recovery-codes.html.twig', [
                    'codes' => $codes,
                    'next' => LoginAction::takeNext($session),
                ]);
            }
            $errors['two_factor.invalid_code'] = [];
        }

        $uri = $this->twoFactor->provisioningUri($secret, $user->username, $this->issuer());

        return $this->responder->render($response, 'auth/two-factor-setup.html.twig', [
            'qr' => $this->twoFactor->qrSvg($uri),
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    private function issuer(): string
    {
        try {
            return $this->config->get()->edition->name;
        } catch (Throwable) {
            return 'LiveScoreTracker';
        }
    }
}
