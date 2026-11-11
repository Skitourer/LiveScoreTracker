<?php

declare(strict_types=1);

namespace LiveScoreTracker\Http;

use LiveScoreTracker\Domain\Security\AccessPolicy;
use LiveScoreTracker\Domain\Security\Permission;
use LiveScoreTracker\Domain\Security\Phase;
use LiveScoreTracker\Domain\Security\User;
use LiveScoreTracker\Infrastructure\Session\Session;
use LogicException;

/**
 * Who is calling, filled in by the middlewares for the current request.
 * Actions and templates read it instead of global state.
 */
final class RequestContext
{
    /** Session key holding the first name typed by a referee on the shared account. */
    public const REFEREE_NAME = 'referee_name';

    private ?Session $session = null;

    public ?User $user = null;
    public AuthState $state = AuthState::Anonymous;
    public Phase $phase = Phase::Preparation;
    public ?string $ip = null;
    public string $deviceId = '';

    public function setSession(Session $session): void
    {
        $this->session = $session;
        $this->deviceId = $session->deviceId;
    }

    public function session(): Session
    {
        return $this->session ?? throw new LogicException('No session: SessionMiddleware did not run.');
    }

    public function hasSession(): bool
    {
        return $this->session !== null;
    }

    public function isAuthenticated(): bool
    {
        return $this->state === AuthState::Authenticated && $this->user !== null;
    }

    public function can(Permission|string $permission): bool
    {
        if (!$this->isAuthenticated() || $this->user === null) {
            return false;
        }
        $permission = $permission instanceof Permission ? $permission : Permission::tryFrom($permission);

        return $permission !== null && AccessPolicy::allows($this->user->role, $permission);
    }

    /**
     * First name of the referee using the shared account, or null.
     */
    public function refereeName(): ?string
    {
        if ($this->session === null) {
            return null;
        }
        $name = $this->session->get(self::REFEREE_NAME);

        return \is_string($name) ? $name : null;
    }

    /**
     * Authenticated user, for actions behind the Authorize middleware.
     */
    public function requireUser(): User
    {
        return $this->user ?? throw new LogicException('No authenticated user.');
    }
}
