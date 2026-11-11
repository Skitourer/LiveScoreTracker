<?php

declare(strict_types=1);

namespace LiveScoreTracker\Http\Middleware;

use LiveScoreTracker\Http\RequestContext;
use LiveScoreTracker\Infrastructure\Session\Session;
use LiveScoreTracker\Infrastructure\Session\SessionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Loads the session from its cookie, gives each browser a long-lived device id
 * (recorded with every entry, spec § 19.1), then saves the session.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public const SESSION_COOKIE = 'lst_session';
    public const DEVICE_COOKIE = 'lst_device';
    public const TRUST_COOKIE = 'lst_trust';
    private const DEVICE_LIFETIME = 400 * 86400;

    public function __construct(
        private readonly SessionStore $store,
        private readonly RequestContext $context,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $deviceId = \is_string($cookies[self::DEVICE_COOKIE] ?? null) && preg_match('/^[a-f0-9]{32}$/', $cookies[self::DEVICE_COOKIE]) === 1
            ? $cookies[self::DEVICE_COOKIE]
            : null;
        $newDevice = $deviceId === null;
        $deviceId ??= bin2hex(random_bytes(16));

        $token = \is_string($cookies[self::SESSION_COOKIE] ?? null) ? $cookies[self::SESSION_COOKIE] : null;
        try {
            $session = $this->store->load($token, $deviceId);
        } catch (Throwable) {
            // Database unreachable or not migrated yet: carry on anonymously.
            $session = Session::start($deviceId);
        }
        $this->context->setSession($session);
        $server = $request->getServerParams();
        $this->context->ip = \is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : null;

        $response = $handler->handle($request);

        $userAgent = $request->getHeaderLine('User-Agent');
        try {
            $keep = $this->store->save($session, $this->context->ip, $userAgent === '' ? null : $userAgent);
        } catch (Throwable) {
            $keep = false;
        }
        $secure = self::isHttps($request);

        if ($keep && ($session->isNew() || $session->previousToken() !== null)) {
            $response = $response->withAddedHeader('Set-Cookie', self::cookie(self::SESSION_COOKIE, $session->token(), null, $secure));
        } elseif (!$keep && !$session->isNew()) {
            $response = $response->withAddedHeader('Set-Cookie', self::cookie(self::SESSION_COOKIE, '', -1, $secure));
        }
        if ($newDevice) {
            $response = $response->withAddedHeader('Set-Cookie', self::cookie(self::DEVICE_COOKIE, $deviceId, self::DEVICE_LIFETIME, $secure));
        }

        return $response;
    }

    public static function cookie(string $name, string $value, ?int $maxAge, bool $secure): string
    {
        $parts = [$name . '=' . $value, 'Path=/', 'HttpOnly', 'SameSite=Lax'];
        if ($maxAge !== null) {
            $parts[] = 'Max-Age=' . max(0, $maxAge);
        }
        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    public static function isHttps(ServerRequestInterface $request): bool
    {
        return $request->getUri()->getScheme() === 'https'
            || strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }
}
