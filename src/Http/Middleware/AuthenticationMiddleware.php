<?php

declare(strict_types=1);

namespace LiveScoreTracker\Http\Middleware;

use LiveScoreTracker\Domain\Security\AccessPolicy;
use LiveScoreTracker\Domain\Security\Role;
use LiveScoreTracker\Http\AuthState;
use LiveScoreTracker\Http\RequestContext;
use LiveScoreTracker\Infrastructure\Persistence\AppState;
use LiveScoreTracker\Infrastructure\Persistence\RefereeDeviceRepository;
use LiveScoreTracker\Infrastructure\Persistence\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Resolves the logged-in account from the session and checks it is still valid:
 * not disabled, not logged out remotely ("disconnect all phones"), and allowed
 * in the current phase (the referee account only while the competition runs).
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly UserRepository $users,
        private readonly AppState $appState,
        private readonly RefereeDeviceRepository $refereeDevices,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $this->resolve();
        } catch (Throwable) {
            // Database unreachable or not migrated yet: nobody is logged in.
            $this->context->user = null;
            $this->context->state = AuthState::Anonymous;
        }

        return $handler->handle($request);
    }

    private function resolve(): void
    {
        $this->context->phase = $this->appState->phase();
        $session = $this->context->session();
        $userId = $session->userId();

        if ($userId !== null) {
            $user = $this->users->find($userId);
            $valid = $user !== null
                && !$user->disabled
                && $session->get('session_version') === $user->sessionVersion
                && AccessPolicy::roleActiveIn($user->role, $this->context->phase)
                && ($user->role !== Role::Referee || !$this->refereeDevices->isBlocked($this->context->deviceId));

            if ($user === null || !$valid) {
                $session->destroy();
            } else {
                $this->context->user = $user;
                $secondFactorDone = $session->get('second_factor') === true;
                $this->context->state = match (true) {
                    $user->role->requiresTwoFactor() && !$secondFactorDone && $user->totpEnabled => AuthState::TwoFactorChallenge,
                    $user->role->requiresTwoFactor() && !$secondFactorDone => AuthState::TwoFactorSetup,
                    $user->role === Role::Referee && !\is_string($session->get(RequestContext::REFEREE_NAME)) => AuthState::RefereeNameRequired,
                    $user->mustChangePassword => AuthState::MustChangePassword,
                    default => AuthState::Authenticated,
                };
            }
        }
    }
}
