<?php

declare(strict_types=1);

namespace LiveScoreTracker\Http\Middleware;

use LiveScoreTracker\Domain\Security\Permission;
use LiveScoreTracker\Http\AuthState;
use LiveScoreTracker\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Psr7\Response;

/**
 * Route guard: requires a logged-in account and, optionally, a permission (spec § 3.2).
 *
 * Visitors who are half-way through logging in are sent to the step they
 * must complete (2FA code, 2FA setup, password change).
 */
final class Authorize implements MiddlewareInterface
{
    /**
     * @param list<AuthState> $allowedStates states that may reach the route
     */
    public function __construct(
        private readonly RequestContext $context,
        private readonly ?Permission $permission = null,
        private readonly array $allowedStates = [AuthState::Authenticated],
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $state = $this->context->state;

        if (!\in_array($state, $this->allowedStates, true)) {
            return self::redirect(match ($state) {
                AuthState::Anonymous => '/login?next=' . rawurlencode(self::safeNext($request)),
                AuthState::TwoFactorChallenge => '/login/2fa',
                AuthState::TwoFactorSetup => '/account/2fa',
                AuthState::RefereeNameRequired => '/referee/name',
                AuthState::MustChangePassword => '/account/password',
                AuthState::Authenticated => '/dashboard',
            });
        }

        if ($this->permission !== null && !$this->context->can($this->permission)) {
            throw new HttpForbiddenException($request);
        }

        return $handler->handle($request);
    }

    public static function redirect(string $location, int $status = 302): ResponseInterface
    {
        return (new Response($status))->withHeader('Location', $location);
    }

    /**
     * Local path to come back to after login (never another site).
     */
    public static function safeNext(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        $query = $request->getUri()->getQuery();

        return $request->getMethod() === 'GET' ? $path . ($query !== '' ? '?' . $query : '') : '/dashboard';
    }

    public static function isLocalPath(string $path): bool
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '\\');
    }
}
