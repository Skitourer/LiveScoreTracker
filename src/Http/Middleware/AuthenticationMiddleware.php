<?php

declare(strict_types=1);

namespace LiveScoreTracker\Http\Middleware;

use LiveScoreTracker\Domain\Security\AccessPolicy;
use LiveScoreTracker\Domain\Security\Phase;
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
 *
 * Once the competition is closed, a referee phone that was logged in keeps its
 * session for one thing: sending the entries still queued on it (and reading
 * the workshop data that tells it the competition is over). Every other page
 * treats it as logged out, and the session works again if the admin reopens.
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
            $this->resolve($request);
        } catch (Throwable) {
            // Database unreachable or not migrated yet: nobody is logged in.
            $this->context->user = null;
            $this->context->state = AuthState::Anonymous;
        }

        return $handler->handle($request);
    }

    private function resolve(ServerRequestInterface $request): void
    {
        $this->context->phase = $this->appState->phase();
        $session = $this->context->session();
        $userId = $session->userId();

        if ($userId !== null) {
            $user = $this->users->find($userId);
            $valid = $user !== null
                && !$user->disabled
                && $session->get('session_version') === $user->sessionVersion
                && ($user->role !== Role::Referee || !$this->refereeDevices->isBlocked($this->context->deviceId));
            $active = $user !== null && AccessPolicy::roleActiveIn($user->role, $this->context->phase);
            $afterClosing = $user !== null && $user->role === Role::Referee && $this->context->phase === Phase::Finished;

            if ($user === null || !$valid || (!$active && !$afterClosing)) {
                $session->destroy();
            } elseif (!$active && !self::isPhoneExchange($request)) {
                // Closed competition: the session stays for the queued entries only.
                $this->context->user = null;
                $this->context->state = AuthState::Anonymous;
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

    /**
     * Requests a phone makes in the background: workshop data and sending entries.
     */
    private static function isPhoneExchange(ServerRequestInterface $request): bool
    {
        return preg_match('#/entry/[a-z0-9_]+/(pack|sync)$#', $request->getUri()->getPath()) === 1;
    }
}
