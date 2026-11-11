<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Auth;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Security\AccessPolicy;
use LiveScoreTracker\Domain\Security\PasswordPolicy;
use LiveScoreTracker\Domain\Security\Role;
use LiveScoreTracker\Domain\Security\User;
use LiveScoreTracker\Http\AuthState;
use LiveScoreTracker\Http\Middleware\Authorize;
use LiveScoreTracker\Http\Middleware\SessionMiddleware;
use LiveScoreTracker\Http\RequestContext;
use LiveScoreTracker\Infrastructure\Persistence\RefereeDeviceRepository;
use LiveScoreTracker\Infrastructure\Persistence\Throttle;
use LiveScoreTracker\Infrastructure\Persistence\TrustedDeviceRepository;
use LiveScoreTracker\Infrastructure\Persistence\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Login with username and password (spec § 19.1).
 *
 * Failures are limited per IP address and per account. For the shared referee
 * account the limit applies per phone, so one child playing with a phone
 * cannot lock out every referee.
 */
final class LoginAction
{
    public const IP_MAX_FAILURES = 20;
    public const ACCOUNT_MAX_FAILURES = 5;

    public function __construct(
        private readonly Responder $responder,
        private readonly UserRepository $users,
        private readonly Throttle $throttle,
        private readonly TrustedDeviceRepository $trustedDevices,
        private readonly RefereeDeviceRepository $refereeDevices,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = $this->responder->context;
        if ($context->state === AuthState::Authenticated) {
            return $this->responder->redirect($response, '/dashboard');
        }

        $next = Responder::query($request, 'next');
        if ($request->getMethod() !== 'POST') {
            return $this->form($response, $next, '', []);
        }

        $next = Responder::field($request, 'next');
        $username = UserRepository::normalize(Responder::field($request, 'username'));
        $password = Responder::field($request, 'password', trim: false);
        $ipKey = 'login-ip:' . ($context->ip ?? 'unknown');

        $blocked = $this->throttle->secondsBlocked($ipKey);
        $user = $username === '' ? null : $this->users->findByUsername($username);
        $accountKey = 'login-user:' . $username . ($user?->role === Role::Referee ? ':' . $context->deviceId : '');
        $blocked = max($blocked, $this->throttle->secondsBlocked($accountKey));
        if ($blocked > 0) {
            return $this->form($response, $next, $username, ['login.blocked' => ['minutes' => Responder::minutes($blocked)]], 429);
        }

        if (!$this->passwordMatches($user, $password) || $user === null || $user->disabled) {
            $this->throttle->recordFailure($ipKey, self::IP_MAX_FAILURES);
            $this->throttle->recordFailure($accountKey, self::ACCOUNT_MAX_FAILURES);
            $this->responder->audit('auth.login_failed', ['username' => mb_substr($username, 0, 64)], $user);

            return $this->form($response, $next, $username, ['login.invalid' => []], 401);
        }

        if (!AccessPolicy::roleActiveIn($user->role, $context->phase)) {
            return $this->form($response, $next, $username, ['login.referee_inactive' => []], 403);
        }
        if ($user->role === Role::Referee && $this->refereeDevices->isBlocked($context->deviceId)) {
            $this->responder->audit('auth.login_blocked_device', [], $user);

            return $this->form($response, $next, $username, ['login.device_blocked' => []], 403);
        }

        $this->throttle->clear($accountKey);
        if (password_needs_rehash($user->passwordHash, \defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            $this->users->setPasswordHashOnly($user->id, PasswordPolicy::hash($password));
        }

        $cookies = $request->getCookieParams();
        $trustToken = \is_string($cookies[SessionMiddleware::TRUST_COOKIE] ?? null) ? $cookies[SessionMiddleware::TRUST_COOKIE] : null;
        $trusted = $user->role->requiresTwoFactor() && $user->totpEnabled
            && $this->trustedDevices->isTrusted($user->id, $context->deviceId, $trustToken);

        $context->session()->logIn($user, secondFactorDone: !$user->role->requiresTwoFactor() || $trusted);
        $context->session()->set('login_next', Authorize::isLocalPath($next) ? $next : '/dashboard');
        $this->users->touchLogin($user->id);

        if ($user->role->requiresTwoFactor() && !$trusted) {
            return $this->responder->redirect($response, $user->totpEnabled ? '/login/2fa' : '/account/2fa');
        }

        if ($user->role === Role::Referee) {
            // The shared account: a known phone finds its referee; otherwise ask who is holding it.
            $device = $this->refereeDevices->find($context->deviceId);
            if ($device === null) {
                return $this->responder->redirect($response, '/referee/name');
            }
            $context->session()->set(RequestContext::REFEREE_NAME, $device['referee_name']);
            $this->refereeDevices->touch($context->deviceId);
        }

        $this->responder->audit('auth.login', $trusted ? ['second_factor' => 'trusted_device'] : [], $user);

        return $this->responder->redirect($response, self::takeNext($context->session()));
    }

    /**
     * Where to go after a complete login.
     */
    public static function takeNext(\LiveScoreTracker\Infrastructure\Session\Session $session): string
    {
        $next = $session->get('login_next');
        $session->remove('login_next');

        return \is_string($next) && Authorize::isLocalPath($next) ? $next : '/dashboard';
    }

    private function passwordMatches(?User $user, string $password): bool
    {
        if ($user === null) {
            // Same work as a real check, so response times do not reveal which usernames exist.
            static $dummy = null;
            $dummy ??= PasswordPolicy::hash('not-a-real-password');
            password_verify($password, $dummy);

            return false;
        }

        return $user->verifyPassword($password);
    }

    /**
     * @param array<string, array<string, int>> $errors translation key => parameters
     */
    private function form(ResponseInterface $response, string $next, string $username, array $errors, int $status = 200): ResponseInterface
    {
        return $this->responder->render($response, 'auth/login.html.twig', [
            'next' => Authorize::isLocalPath($next) ? $next : '',
            'username' => $username,
            'errors' => $errors,
        ], $status);
    }
}
