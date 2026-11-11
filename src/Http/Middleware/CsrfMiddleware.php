<?php

declare(strict_types=1);

namespace LiveScoreTracker\Http\Middleware;

use LiveScoreTracker\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpBadRequestException;

/**
 * Anti-CSRF token on every form (spec § 19.2).
 *
 * State-changing requests must send the session's token in the `_csrf` field
 * or the `X-CSRF-Token` header. Machine endpoints authenticated by their own
 * token (deployment) are exempt.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public const FIELD = '_csrf';
    private const EXEMPT_PATHS = ['/internal/migrate'];

    public function __construct(private readonly RequestContext $context) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (\in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) || \in_array($request->getUri()->getPath(), self::EXEMPT_PATHS, true)) {
            return $handler->handle($request);
        }

        $body = $request->getParsedBody();
        $sent = \is_array($body) && \is_string($body[self::FIELD] ?? null) ? $body[self::FIELD] : $request->getHeaderLine('X-CSRF-Token');
        $expected = $this->context->session()->get('csrf');

        if (!\is_string($expected) || $sent === '' || !hash_equals($expected, $sent)) {
            throw new HttpBadRequestException($request, 'csrf');
        }

        return $handler->handle($request);
    }
}
