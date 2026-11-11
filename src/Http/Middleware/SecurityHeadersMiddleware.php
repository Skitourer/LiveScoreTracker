<?php

declare(strict_types=1);

namespace LiveScoreTracker\Http\Middleware;

use LiveScoreTracker\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Security headers on every response (spec § 19.2), including a strict
 * Content-Security-Policy: only our own scripts, styles and images.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public function __construct(private readonly RequestContext $context) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Pages of logged-in users must not be kept by the browser or shared caches.
        if ($this->context->user !== null && !$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }

        return $response;
    }
}
